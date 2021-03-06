<?php

declare(strict_types=1);

/*
 * This file is part of the "jobrouter_process" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Brotkrueml\JobRouterProcess\Domain\Finishers;

use Brotkrueml\JobRouterBase\Domain\Finishers\AbstractTransferFinisher;
use Brotkrueml\JobRouterBase\Domain\Preparers\FormFieldValuesPreparer;
use Brotkrueml\JobRouterBase\Enumeration\FieldTypeEnumeration;
use Brotkrueml\JobRouterProcess\Domain\Model\Processtablefield;
use Brotkrueml\JobRouterProcess\Domain\Model\Step;
use Brotkrueml\JobRouterProcess\Domain\Model\Transfer;
use Brotkrueml\JobRouterProcess\Domain\Repository\StepRepository;
use Brotkrueml\JobRouterProcess\Exception\CommonParameterNotFoundException;
use Brotkrueml\JobRouterProcess\Exception\InvalidFieldTypeException;
use Brotkrueml\JobRouterProcess\Exception\MissingFinisherOptionException;
use Brotkrueml\JobRouterProcess\Exception\MissingProcessTableFieldException;
use Brotkrueml\JobRouterProcess\Exception\ProcessNotFoundException;
use Brotkrueml\JobRouterProcess\Exception\StepNotFoundException;
use Brotkrueml\JobRouterProcess\Transfer\Preparer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * @internal
 */
final class StartInstanceFinisher extends AbstractTransferFinisher implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Preparer */
    private $preparer;

    /** @var StepRepository */
    private $stepRepository;

    private $stepParameters = [
        'initiator',
        'jobfunction',
        'pool',
        'priority',
        'simulation',
        'summary',
        'username',
    ];

    /** @var Step */
    private $step;

    /** @var Transfer */
    private $transfer;

    public function injectPreparer(Preparer $preparer): void
    {
        $this->preparer = $preparer;
    }

    public function injectStepRepository(StepRepository $stepRepository): void
    {
        $this->stepRepository = $stepRepository;
    }

    protected function process(): void
    {
        $this->determineStep($this->parseOption('handle'));

        $this->initialiseTransfer();
        $this->prepareStepParametersForTransfer();
        $this->prepareTypeForTransfer();
        $this->prepareProcessTableForTransfer();
        $this->preparer->store($this->transfer);
    }

    private function determineStep(?string $handle): void
    {
        if (empty($handle)) {
            $message = \sprintf(
                'Step handle in StartInstanceFinisher of form with identifier "%s" is not defined.',
                $this->getFormIdentifier()
            );

            $this->logger->critical($message);

            throw new MissingFinisherOptionException($message, 1581270462);
        }

        $this->step = $this->stepRepository->findOneByHandle($handle);

        if (empty($this->step)) {
            $message = \sprintf(
                'Step with handle "%s" is not available, defined in form with identifier "%s"',
                $handle,
                $this->getFormIdentifier()
            );

            $this->logger->critical($message);

            throw new StepNotFoundException($message, 1581270832);
        }

        if (empty($this->step->getProcess())) {
            $message = \sprintf(
                'Process for step with handle "%s" is not available, defined in form with identifier "%s"',
                $handle,
                $this->getFormIdentifier()
            );

            $this->logger->critical($message);

            throw new ProcessNotFoundException($message, 1581281395);
        }
    }

    private function initialiseTransfer(): void
    {
        $this->transfer = new Transfer();
        $this->transfer->setCrdate(\time());
        $this->transfer->setStepUid($this->step->getUid());
        $this->transfer->setCorrelationId($this->correlationId);
    }

    private function prepareStepParametersForTransfer(): void
    {
        foreach ($this->stepParameters as $parameter) {
            $value = $this->parseOption($parameter);

            if (empty($value)) {
                continue;
            }

            $setter = 'set' . \ucfirst($parameter);
            if (!\method_exists($this->transfer, $setter)) {
                throw new CommonParameterNotFoundException(
                    \sprintf('Method "%s" in Transfer domain model not found', $setter),
                    1581703904
                );
            }

            $value = $this->variableResolver->resolve(FieldTypeEnumeration::TEXT, $value);

            $this->transfer->$setter($value);
        }
    }

    private function prepareTypeForTransfer(): void
    {
        $type = $this->parseOption('type');

        if (empty($type)) {
            return;
        }

        $type = $this->variableResolver->resolve(FieldTypeEnumeration::TEXT, $type);
        $this->transfer->setType($type);
    }

    private function prepareProcessTableForTransfer(): void
    {
        if (!isset($this->options['processtable']) || !\is_array($this->options['processtable'])) {
            return;
        }

        $formValues = (new FormFieldValuesPreparer())->prepareForSubstitution(
            $this->finisherContext->getFormRuntime()->getFormDefinition()->getElements(),
            $this->finisherContext->getFormValues()
        );
        $processTableFields = $this->prepareProcessTableFields();
        $processTable = [];
        foreach ($this->options['processtable'] as $processTableField => $value) {
            if (!\array_key_exists($processTableField, $processTableFields)) {
                throw new MissingProcessTableFieldException(
                    \sprintf(
                        'Process table field "%s" is used in form with identifier "%s" but not defined in process link "%s"',
                        $processTableField,
                        $this->getFormIdentifier(),
                        $this->step->getProcess()->getName()
                    ),
                    1585930166
                );
            }

            $value = $this->variableResolver->resolve(
                $processTableFields[$processTableField]->getType(),
                $value
            );

            $value = $this->resolveFormFields($formValues, $value);

            $processTable[$processTableField] = $this->considerTypeForFieldValue(
                $value,
                $processTableFields[$processTableField]->getType(),
                $processTableFields[$processTableField]->getFieldSize()
            );
        }

        $this->transfer->setProcesstable($processTable);
    }

    /**
     * @return Processtablefield[]
     */
    private function prepareProcessTableFields(): array
    {
        /** @var Processtablefield[] $fields */
        $fields = $this->step->getProcess()->getProcesstablefields();

        $processTableFields = [];
        foreach ($fields as $field) {
            $processTableFields[$field->getName()] = $field;
        }

        return $processTableFields;
    }

    private function considerTypeForFieldValue($value, int $type, int $fieldSize)
    {
        switch ($type) {
            case FieldTypeEnumeration::TEXT:
                $value = (string)$value;

                if ($fieldSize) {
                    $value = \substr($value, 0, $fieldSize);
                }

                return $value;
            case FieldTypeEnumeration::INTEGER:
                return $value === '' ? '' : (int)$value;
        }

        throw new InvalidFieldTypeException(
            \sprintf('The field type "%d" is invalid', $type),
            1581344823
        );
    }
}
