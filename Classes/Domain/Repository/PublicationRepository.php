<?php

declare(strict_types=1);

namespace In2code\Publications\Domain\Repository;

use In2code\Publications\Domain\Model\Dto\Filter;
use In2code\Publications\Domain\Model\Publication;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Class PublicationRepository
 */
class PublicationRepository extends AbstractRepository
{
    /**
     * @param Filter $filter
     * @return object[]|QueryResultInterface
     * @throws InvalidQueryException
     */
    public function findByFilter(Filter $filter)
    {
        $query = $this->createQuery();
        $this->filterQuery($query, $filter);
        $this->setOrderingsByFilterSettings($query, $filter);
        $results = $query->execute();

/*
$dbParser = $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\Storage\Typo3DbQueryParser::class);

$doctrineQueryBuilder = $dbParser->convertQueryToDoctrineQueryBuilder($query);
$sql = $doctrineQueryBuilder->getSQL();
$parameters = $doctrineQueryBuilder->getParameters();
\TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump(['sql' => $sql, 'parameters' => $parameters], __METHOD__ . ':' . __LINE__);
*/

        return $this->convertToAscendingArray($results);
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @return void
     * @throws InvalidQueryException
     */
    protected function filterQuery(QueryInterface $query, Filter $filter)
    {
        $and = [];
        if ($filter->isFilterFlexFormSet()) {
            $and = $this->filterQueryByKeywords($query, $filter, $and);
            $and = $this->filterQueryByTags($query, $filter, $and);
            $and = $this->filterQueryByTimeframe($query, $filter, $and);
            $and = $this->filterQueryByTimerange($query, $filter, $and);
            $and = $this->filterQueryByBibtypes($query, $filter, $and);
            $and = $this->filterQueryByStatus($query, $filter, $and);
            $and = $this->filterQueryByAuthor($query, $filter, $and);
            $and = $this->filterQueryByExternFilter($query, $filter, $and);
            $and = $this->filterQueryByReviewFilter($query, $filter, $and);
            $and = $this->filterQueryByRecords($query, $filter, $and);
        }
        if ($filter->isFilterFrontendSet()) {
            $and = $this->filterQueryBySearchterms($query, $filter, $and);
            $and = $this->filterQueryByYear($query, $filter, $and);
            $and = $this->filterQueryByAuthorstring($query, $filter, $and);
            $and = $this->filterQueryByDocumenttype($query, $filter, $and);
        }
        if ($and !== []) {
            $query->matching($query->logicalAnd($and));
        }
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByKeywords(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isKeywordsSet()) {
            $or = [];
            foreach ($filter->getKeywords() as $keyword) {
                $or[] = $query->like('keywords', '%' . $keyword . '%');
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByTags(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isTagsSet()) {
            $or = [];
            foreach ($filter->getTags() as $tag) {
                $or[] = $query->like('tags', '%' . $tag . '%');
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByTimeframe(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isTimeFrameSet()) {
            $and[] = $query->greaterThan('year', $filter->getDateFromTimeFrame()->format('Y'));
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByTimerange(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isTimerangeSet()) {
            // exclusive (excluding the current)
            $greaterMonths = [];
            // inclusive (including the current)
            $monthsUp = [];
            // exclusive (excluding the current)
            $lowerMonths = [];
            // inclusive (including the current)
            $monthsDown = [];
            // exclusive (excluding the current ones)
            $unionMonths = [];
            // inclusive (including the current ones)
            $allMonths = [];

            for ($n = 1; $n <= 12; $n++) {
                if ($filter->getTimerangeStart()) {
                    if ($n > $filter->getTimerangeStart()->format('n')) {
                        $greaterMonths[] = $n;
                    }
                    if ($n >= $filter->getTimerangeStart()->format('n')) {
                        $monthsUp[] = $n;
                    }
                }
                if ($filter->getTimerangeEnd()) {
                    if ($n < (int) $filter->getTimerangeEnd()->format('n')) {
                        $lowerMonths[] = $n;
                    }
                    if ($n <= (int) $filter->getTimerangeEnd()->format('n')) {
                        $monthsDown[] = $n;
                    }
                }
            }
            for ($n = 1; $n <= 12; $n++) {
                if (in_array($n, $greaterMonths) && in_array($n, $lowerMonths)) {
                    $unionMonths[] = $n;
                }
                if (in_array($n, $monthsUp) && in_array($n, $monthsDown)) {
                    $allMonths[] = $n;
                }
            }

            if (count($allMonths)
                && $filter->getTimerangeStart()
                && $filter->getTimerangeEnd()
                && $filter->getTimerangeStart()->format('Y') === $filter->getTimerangeEnd()->format('Y')
            ) {

                $and[] = $query->logicalAnd([
                    // start
                    $query->logicalOr([
                        $query->logicalAnd([
                            $query->equals('year', $filter->getTimerangeStart()->format('Y')),
                            $query->logicalOr([
                                $query->logicalAnd([
                                    $query->logicalOr([
                                        $query->equals('month', $filter->getTimerangeStart()->format('n')),
                                        $query->equals('month', 0),
                                    ]),
                                    $query->logicalOr([
                                        $query->greaterThanOrEqual('day', $filter->getTimerangeStart()->format('j')),
                                        $query->equals('day', 0),
                                    ]),
                                ]),
                                $query->logicalOr([
                                    $query->in('month', $allMonths),
                                    $query->equals('month', 0),
                                ]),
                            ]),
                        ]),
                        $query->greaterThan('year', $filter->getTimerangeStart()->format('Y')),
                    ]),
                    // end
                    $query->logicalOr([
                        $query->logicalAnd([
                            $query->equals('year', $filter->getTimerangeEnd()->format('Y')),
                            $query->logicalOr([
                                $query->logicalAnd([
                                    $query->logicalOr([
                                        $query->equals('month', $filter->getTimerangeEnd()->format('n')),
                                        $query->equals('month', 0),
                                    ]),
                                    $query->logicalOr([
                                        $query->lessThanOrEqual('day', $filter->getTimerangeEnd()->format('j')),
                                        $query->equals('day', 0),
                                    ]),
                                ]),
                                $query->logicalOr([
                                    $query->in('month', $allMonths),
                                    $query->equals('month', 0),
                                ]),
                            ]),
                        ]),
                        $query->lessThan('year', $filter->getTimerangeEnd()->format('Y')),
                    ]),
                ]);

            } else {

                $and[] = $query->logicalAnd([
                    // start
                    $query->logicalOr([
                        $query->logicalAnd([
                            $query->equals('year', $filter->getTimerangeStart()->format('Y')),
                            $query->logicalOr([
                                $query->logicalAnd([
                                    $query->logicalOr([
                                        $query->equals('month', $filter->getTimerangeStart()->format('n')),
                                        $query->equals('month', 0),
                                    ]),
                                    $query->logicalOr([
                                        $query->greaterThanOrEqual('day', $filter->getTimerangeStart()->format('j')),
                                        $query->equals('day', 0),
                                    ]),
                                ]),
                                $query->equals('month', 0),
                            ]),
                        ]),
                        $query->greaterThan('year', $filter->getTimerangeStart()->format('Y')),
                    ]),
                    // end
                    $query->logicalOr([
                        $query->logicalAnd([
                            $query->equals('year', $filter->getTimerangeEnd()->format('Y')),
                            $query->logicalOr([
                                $query->logicalAnd([
                                    $query->logicalOr([
                                        $query->equals('month', $filter->getTimerangeEnd()->format('n')),
                                        $query->equals('month', 0),
                                    ]),
                                    $query->logicalOr([
                                        $query->lessThanOrEqual('day', $filter->getTimerangeEnd()->format('j')),
                                        $query->equals('day', 0),
                                    ]),
                                ]),
                                $query->equals('month', 0),
                            ]),
                        ]),
                        $query->lessThan('year', $filter->getTimerangeEnd()->format('Y')),
                    ]),
                ]);
            }

        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     */
    protected function filterQueryByBibtypes(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isBibtypesSet()) {
            $or = [];
            foreach ($filter->getBibtypes() as $bibtype) {
                $or[] = $query->equals('bibtype', $bibtype);
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     */
    protected function filterQueryByStatus(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isStatusSet()) {
            $or = [];
            foreach ($filter->getStatus() as $status) {
                $or[] = $query->equals('status', $status);
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByAuthor(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isAuthorSet()) {
            $or = [];
            foreach ($filter->getAuthors() as $author) {
                $or[] = $query->contains('authors', $author);
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     */
    protected function filterQueryByExternFilter(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isExternOrIntern()) {
            $and[] = $query->equals('extern', ($filter->isIntern() ? 0 : 1));
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     */
    protected function filterQueryByReviewFilter(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isReviewed()) {
            $and[] = $query->equals('reviewed', ($filter->isReview() ? 0 : 1));
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByRecords(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isRecordsSet()) {
            $and[] = $query->in('pid', $filter->getRecords());
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryBySearchterms(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isSearchtermSet()) {
            $or = [];
            foreach ($filter->getSearchterms() as $searchterm) {
                $or[] = $query->like('title', '%' . $searchterm . '%');
                $or[] = $query->like('abstract', '%' . $searchterm . '%');
                $or[] = $query->like('bibtype', '%' . $searchterm . '%');
                $or[] = $query->like('type', '%' . $searchterm . '%');
                $or[] = $query->like('language', '%' . $searchterm . '%');
                $or[] = $query->like('citeid', '%' . $searchterm . '%');
                $or[] = $query->like('isbn', '%' . $searchterm . '%');
                $or[] = $query->like('issn', '%' . $searchterm . '%');
                $or[] = $query->like('doi', '%' . $searchterm . '%');
                $or[] = $query->like('organization', '%' . $searchterm . '%');
                $or[] = $query->like('school', '%' . $searchterm . '%');
                $or[] = $query->like('institution', '%' . $searchterm . '%');
                $or[] = $query->like('institute', '%' . $searchterm . '%');
                $or[] = $query->like('booktitle', '%' . $searchterm . '%');
                $or[] = $query->like('journal', '%' . $searchterm . '%');
                $or[] = $query->like('edition', '%' . $searchterm . '%');
                $or[] = $query->like('volume', '%' . $searchterm . '%');
                $or[] = $query->like('publisher', '%' . $searchterm . '%');
                $or[] = $query->like('address', '%' . $searchterm . '%');
                $or[] = $query->like('chapter', '%' . $searchterm . '%');
                $or[] = $query->like('series', '%' . $searchterm . '%');
                $or[] = $query->like('edition', '%' . $searchterm . '%');
                $or[] = $query->like('howpublished', '%' . $searchterm . '%');
                $or[] = $query->like('editor', '%' . $searchterm . '%');
                $or[] = $query->like('affiliation', '%' . $searchterm . '%');
                $or[] = $query->like('eventName', '%' . $searchterm . '%');
                $or[] = $query->like('eventPlace', '%' . $searchterm . '%');
                $or[] = $query->like('number', '%' . $searchterm . '%');
                $or[] = $query->like('number2', '%' . $searchterm . '%');
                $or[] = $query->like('keywords', '%' . $searchterm . '%');
                $or[] = $query->like('tags', '%' . $searchterm . '%');
                $or[] = $query->like('note', '%' . $searchterm . '%');
                $or[] = $query->like('annotation', '%' . $searchterm . '%');
                $or[] = $query->like('miscellaneous', '%' . $searchterm . '%');
                $or[] = $query->like('miscellaneous2', '%' . $searchterm . '%');
                $or[] = $query->like('borrowed_by', '%' . $searchterm . '%');
                $or[] = $query->like('authors.firstName', '%' . $searchterm . '%');
                $or[] = $query->like('authors.lastName', '%' . $searchterm . '%');
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByYear(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isYearSet()) {
            $and[] = $query->equals('year', $filter->getYear());
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByDocumenttype(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isDocumenttypeSet()) {
            $and[] = $query->equals('bibtype', $filter->getDocumenttype());
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @param array $and
     * @return array
     * @throws InvalidQueryException
     */
    protected function filterQueryByAuthorstring(QueryInterface $query, Filter $filter, array $and): array
    {
        if ($filter->isAuthorstringSet()) {
            $or = [];
            foreach ($filter->getAuthorstrings() as $authorstring) {
                $or[] = $query->like('authors.firstName', '%' . $authorstring . '%');
                $or[] = $query->like('authors.lastName', '%' . $authorstring . '%');
            }
            $and[] = $query->logicalOr($or);
        }
        return $and;
    }

    /**
     * @param QueryInterface $query
     * @param Filter $filter
     * @return void
     */
    protected function setOrderingsByFilterSettings(QueryInterface $query, Filter $filter)
    {
        $query->setOrderings($filter->getGroupByArrayForQuery());
    }

    /**
     * Convert results to array and add a number to the records
     *
     * @param QueryResultInterface $results
     * @return array
     */
    protected function convertToAscendingArray(QueryResultInterface $results): array
    {
        $i = 0;
        $resultsRaw = $results->toArray();
        usort($resultsRaw, [$this, 'compareCallbackByDate']);
        return $resultsRaw;
    }

    /**
     * Callback function to sort by a date
     *
     * @param Publication $p1
     * @param Publication $p2
     * @return int 0 or -1 or 1
     */
    public function compareCallbackByDate(Publication $p1, Publication $p2): int
    {
        return $p2->getDate() <=> $p1->getDate();
    }
}
