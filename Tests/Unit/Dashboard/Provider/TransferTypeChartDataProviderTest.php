<?php

/*
 * This file is part of the "jobrouter_process" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Brotkrueml\JobRouterProcess\Tests\Unit\Dashboard\Provider;

use Brotkrueml\JobRouterProcess\Dashboard\Provider\TransferTypeChartDataProvider;
use Doctrine\DBAL\Driver\ResultStatement;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;

class TransferTypeChartDataProviderTest extends TestCase
{
    /**
     * @var Stub|ResultStatement
     */
    private $statementStub;

    /**
     * @var TransferTypeChartDataProvider
     */
    private $subject;

    protected function setUp(): void
    {
        if (!\class_exists(Typo3Version::class)) {
            self::markTestSkipped('Only TYPO3 v10+');
        }

        $languageServiceStub = $this->createStub(LanguageService::class);
        $languageServiceStub
            ->method('sL')
            ->willReturn('unknown');

        $this->statementStub = $this->createStub(ResultStatement::class);

        $queryBuilderStub = $this->createStub(QueryBuilder::class);
        $queryBuilderStub
            ->method('selectLiteral')
            ->willReturn($queryBuilderStub);
        $queryBuilderStub
            ->method('from')
            ->willReturn($queryBuilderStub);
        $queryBuilderStub
            ->method('groupBy')
            ->willReturn($queryBuilderStub);
        $queryBuilderStub
            ->method('orderBy')
            ->willReturn($queryBuilderStub);
        $queryBuilderStub
            ->method('execute')
            ->willReturn($this->statementStub);

        $this->subject = new TransferTypeChartDataProvider(
            $languageServiceStub,
            $queryBuilderStub
        );
    }

    /**
     * @test
     * @dataProvider dataProviderForGetChartData
     * @param array $queryResult
     * @param array $expected
     */
    public function getChartData(array $queryResult, array $expected): void
    {
        $this->statementStub
            ->method('fetchAll')
            ->willReturn($queryResult);

        self::assertSame($expected, $this->subject->getChartData());
    }

    public function dataProviderForGetChartData(): \Generator
    {
        yield 'Returns empty arrays when no transfers available' => [
            [],
            [
                'datasets' => [
                    [
                        'backgroundColor' => [],
                        'data' => [],
                    ],
                ],
                'labels' => [],
            ]
        ];

        yield 'Returns unknown type when type is empty available' => [
            [
                [
                    'type' => '',
                    'count' => 3,
                ],
            ],
            [
                'datasets' => [
                    [
                        'backgroundColor' => ['#fc3'],
                        'data' => [3],
                    ],
                ],
                'labels' => ['unknown'],
            ]
        ];

        yield 'Returns types correctly' => [
            [
                [
                    'type' => 'some type',
                    'count' => 1,
                ],
                [
                    'type' => 'another type',
                    'count' => 2,
                ],
                [
                    'type' => 'different type',
                    'count' => 3,
                ],
                [
                    'type' => 'funny type',
                    'count' => 4,
                ],
                [
                    'type' => 'foo type',
                    'count' => 5,
                ],
                [
                    'type' => 'bar type',
                    'count' => 6,
                ],
                [
                    'type' => 'baz type',
                    'count' => 7,
                ],
            ],
            [
                'datasets' => [
                    [
                        'backgroundColor' => [
                            '#fc3',
                            '#ff8700',
                            '#a4276a',
                            '#1a568f',
                            '#4c7e3a',
                            '#69bbb5',
                            '#fc3',
                        ],
                        'data' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                            6,
                            7,
                        ],
                    ],
                ],
                'labels' => [
                    'some type',
                    'another type',
                    'different type',
                    'funny type',
                    'foo type',
                    'bar type',
                    'baz type',
                ],
            ]
        ];
    }
}