<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\API;

use Exception;
use Piwik\API\DataTableManipulator\Flattener;
use Piwik\API\DataTableManipulator\LabelFilter;
use Piwik\API\DataTableManipulator\ReportTotalsCalculator;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Filter\PivotByDimension;
use Piwik\Metrics\Formatter;
use Piwik\Plugin\ProcessedMetric;
use Piwik\Plugin\Report;

/**
 * Processes DataTables that should be served through Piwik's APIs. This processing handles
 * special query parameters and computes processed metrics. It does not included rendering to
 * output formats (eg, 'xml').
 */
class DataTablePostProcessor
{
    const PROCESSED_METRICS_COMPUTED_FLAG = 'processed_metrics_computed';

    /**
     * @var null|Report
     */
    private $report;

    /**
     * @var string[]
     */
    private $request;

    /**
     * @var string
     */
    private $apiModule;

    /**
     * @var string
     */
    private $apiMethod;

    /**
     * @var Inconsistencies
     */
    private $apiInconsistencies;

    /**
     * @var Formatter
     */
    private $formatter;

    private $callbackBeforeGenericFilters;
    private $callbackAfterGenericFilters;

    /**
     * Constructor.
     */
    public function __construct($apiModule, $apiMethod, $request)
    {
        $this->apiModule = $apiModule;
        $this->apiMethod = $apiMethod;
        $this->setRequest($request);

        $this->report = Report::factory($apiModule, $apiMethod);
        $this->apiInconsistencies = new Inconsistencies();
        $this->setFormatter(new Formatter());
    }

    public function setFormatter(Formatter $formatter)
    {
        $this->formatter = $formatter;
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }

    public function setCallbackBeforeGenericFilters($callbackBeforeGenericFilters)
    {
        $this->callbackBeforeGenericFilters = $callbackBeforeGenericFilters;
    }

    public function setCallbackAfterGenericFilters($callbackAfterGenericFilters)
    {
        $this->callbackAfterGenericFilters = $callbackAfterGenericFilters;
    }

    /**
     * Apply post-processing logic to a DataTable of a report for an API request.
     *
     * @param DataTableInterface $dataTable The data table to process.
     * @return DataTableInterface A new data table.
     */
    public function process(DataTableInterface $dataTable)
    {
        // TODO: when calculating metrics before hand, only calculate for needed metrics, not all. NOTE:
        //       this is non-trivial since it will require, eg, to make sure processed metrics aren't added
        //       after pivotBy is handled.
        $dataTable = $this->applyPivotByFilter($dataTable);
        $dataTable = $this->applyTotalsCalculator($dataTable);
        $dataTable = $this->applyFlattener($dataTable);

        if ($this->callbackBeforeGenericFilters) {
            call_user_func($this->callbackBeforeGenericFilters, $dataTable);
        }

        $dataTable = $this->applyGenericFilters($dataTable);
        $this->applyComputeProcessedMetrics($dataTable);

        if ($this->callbackAfterGenericFilters) {
            call_user_func($this->callbackAfterGenericFilters, $dataTable);
        }

        // we automatically safe decode all datatable labels (against xss)
        $dataTable->queueFilter('SafeDecodeLabel');
        $dataTable = $this->convertSegmentValueToSegment($dataTable);
        $dataTable = $this->applyQueuedFilters($dataTable);
        $dataTable = $this->applyRequestedColumnDeletion($dataTable);
        $dataTable = $this->applyLabelFilter($dataTable);
        $dataTable = $this->applyMetricsFormatting($dataTable);
        return $dataTable;
    }

    private function convertSegmentValueToSegment(DataTableInterface $dataTable)
    {
        $dataTable->filter('AddSegmentBySegmentValue', array($this->report));
        $dataTable->filter('ColumnCallbackDeleteMetadata', array('segmentValue'));

        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyPivotByFilter(DataTableInterface $dataTable)
    {
        $pivotBy = Common::getRequestVar('pivotBy', false, 'string', $this->request);
        if (!empty($pivotBy)) {
            $this->applyComputeProcessedMetrics($dataTable);

            $reportId = $this->apiModule . '.' . $this->apiMethod;
            $pivotByColumn = Common::getRequestVar('pivotByColumn', false, 'string', $this->request);
            $pivotByColumnLimit = Common::getRequestVar('pivotByColumnLimit', false, 'int', $this->request);

            $dataTable->filter('ColumnCallbackDeleteMetadata', array('segmentValue'));
            $dataTable->filter('ColumnCallbackDeleteMetadata', array('segment'));
            $dataTable->filter('PivotByDimension', array($reportId, $pivotBy, $pivotByColumn, $pivotByColumnLimit,
                PivotByDimension::isSegmentFetchingEnabledInConfig()));
        }
        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTable|DataTableInterface|DataTable\Map
     */
    public function applyFlattener($dataTable)
    {
        if (Common::getRequestVar('flat', '0', 'string', $this->request) == '1') {
            $flattener = new Flattener($this->apiModule, $this->apiMethod, $this->request);
            if (Common::getRequestVar('include_aggregate_rows', '0', 'string', $this->request) == '1') {
                $flattener->includeAggregateRows();
            }

            $recursiveLabelSeparator = ' - ';
            if ($this->report) {
                $recursiveLabelSeparator = $this->report->getRecursiveLabelSeparator();
            }

            $dataTable = $flattener->flatten($dataTable, $recursiveLabelSeparator);
        }
        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyTotalsCalculator($dataTable)
    {
        if (1 == Common::getRequestVar('totals', '1', 'integer', $this->request)) {
            $reportTotalsCalculator = new ReportTotalsCalculator($this->apiModule, $this->apiMethod, $this->request);
            $dataTable     = $reportTotalsCalculator->calculate($dataTable);
        }
        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyGenericFilters($dataTable)
    {
        // if the flag disable_generic_filters is defined we skip the generic filters
        if (0 == Common::getRequestVar('disable_generic_filters', '0', 'string', $this->request)) {
            $this->applyProcessedMetricsGenericFilters($dataTable);

            $genericFilter = new DataTableGenericFilter($this->request, $this->report);

            $self = $this;
            $report = $this->report;
            $dataTable->filter(function (DataTable $table) use ($genericFilter, $report, $self) {
                $processedMetrics = Report::getProcessedMetricsForTable($table, $report);
                if ($genericFilter->areProcessedMetricsNeededFor($processedMetrics)) {
                    $self->computeProcessedMetrics($table);
                }
            });

            $label = self::getLabelFromRequest($this->request);
            if (!empty($label)) {
                $genericFilter->disableFilters(array('Limit', 'Truncate'));
            }

            $genericFilter->filter($dataTable);
        }

        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyProcessedMetricsGenericFilters($dataTable)
    {
        $addNormalProcessedMetrics = null;
        try {
            $addNormalProcessedMetrics = Common::getRequestVar(
                'filter_add_columns_when_show_all_columns', null, 'integer', $this->request);
        } catch (Exception $ex) {
            // ignore
        }

        if ($addNormalProcessedMetrics !== null) {
            $dataTable->filter('AddColumnsProcessedMetrics', array($addNormalProcessedMetrics));
        }

        $addGoalProcessedMetrics = null;
        try {
            $addGoalProcessedMetrics = Common::getRequestVar(
                'filter_update_columns_when_show_all_goals', null, 'integer', $this->request);
        } catch (Exception $ex) {
            // ignore
        }

        if ($addGoalProcessedMetrics !== null) {
            $idGoal = Common::getRequestVar(
                'idGoal', DataTable\Filter\AddColumnsProcessedMetricsGoal::GOALS_OVERVIEW, 'string', $this->request);

            $dataTable->filter('AddColumnsProcessedMetricsGoal', array($ignore = true, $idGoal));
        }

        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyQueuedFilters($dataTable)
    {
        // if the flag disable_queued_filters is defined we skip the filters that were queued
        if (Common::getRequestVar('disable_queued_filters', 0, 'int', $this->request) == 0) {
            $dataTable->applyQueuedFilters();
        }
        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyRequestedColumnDeletion($dataTable)
    {
        // use the ColumnDelete filter if hideColumns/showColumns is provided (must be done
        // after queued filters are run so processed metrics can be removed, too)
        $hideColumns = Common::getRequestVar('hideColumns', '', 'string', $this->request);
        $showColumns = Common::getRequestVar('showColumns', '', 'string', $this->request);
        if (!empty($hideColumns)
            || !empty($showColumns)
        ) {
            $dataTable->filter('ColumnDelete', array($hideColumns, $showColumns));
        } else {
            $this->removeTemporaryMetrics($dataTable);
        }

        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     */
    public function removeTemporaryMetrics(DataTableInterface $dataTable)
    {
        $allColumns = !empty($this->report) ? $this->report->getAllMetrics() : array();

        $report = $this->report;
        $dataTable->filter(function (DataTable $table) use ($report, $allColumns) {
            $processedMetrics = Report::getProcessedMetricsForTable($table, $report);

            $allTemporaryMetrics = array();
            foreach ($processedMetrics as $metric) {
                $allTemporaryMetrics = array_merge($allTemporaryMetrics, $metric->getTemporaryMetrics());
            }

            if (!empty($allTemporaryMetrics)) {
                $table->filter('ColumnDelete', array($allTemporaryMetrics));
            }
        });
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyLabelFilter($dataTable)
    {
        $label = self::getLabelFromRequest($this->request);

        // apply label filter: only return rows matching the label parameter (more than one if more than one label)
        if (!empty($label)) {
            $addLabelIndex = Common::getRequestVar('labelFilterAddLabelIndex', 0, 'int', $this->request) == 1;

            $filter = new LabelFilter($this->apiModule, $this->apiMethod, $this->request);
            $dataTable = $filter->filter($label, $dataTable, $addLabelIndex);
        }
        return $dataTable;
    }

    /**
     * @param DataTableInterface $dataTable
     * @return DataTableInterface
     */
    public function applyMetricsFormatting($dataTable)
    {
        $formatMetrics = Common::getRequestVar('format_metrics', 0, 'string', $this->request);
        if ($formatMetrics == '0') {
            return $dataTable;
        }

        // in Piwik 2.X & below, metrics are not formatted in API responses except for percents.
        // this code implements this inconsistency
        $onlyFormatPercents = $formatMetrics === 'bc';

        $metricsToFormat = null;
        if ($onlyFormatPercents) {
            $metricsToFormat = $this->apiInconsistencies->getPercentMetricsToFormat();
        }

        $dataTable->filter(array($this->formatter, 'formatMetrics'), array($this->report, $metricsToFormat));
        return $dataTable;
    }

    /**
     * Returns the value for the label query parameter which can be either a string
     * (ie, label=...) or array (ie, label[]=...).
     *
     * @param array $request
     * @return array
     */
    public static function getLabelFromRequest($request)
    {
        $label = Common::getRequestVar('label', array(), 'array', $request);
        if (empty($label)) {
            $label = Common::getRequestVar('label', '', 'string', $request);
            if (!empty($label)) {
                $label = array($label);
            }
        }

        $label = self::unsanitizeLabelParameter($label);
        return $label;
    }

    public static function unsanitizeLabelParameter($label)
    {
        // this is needed because Proxy uses Common::getRequestVar which in turn
        // uses Common::sanitizeInputValue. This causes the > that separates recursive labels
        // to become &gt; and we need to undo that here.
        $label = Common::unsanitizeInputValues($label);
        return $label;
    }

    public function computeProcessedMetrics(DataTable $dataTable)
    {
        if ($dataTable->getMetadata(self::PROCESSED_METRICS_COMPUTED_FLAG)) {
            return;
        }

        /** @var ProcessedMetric[] $processedMetrics */
        $processedMetrics = Report::getProcessedMetricsForTable($dataTable, $this->report);
        if (empty($processedMetrics)) {
            return;
        }

        $dataTable->setMetadata(self::PROCESSED_METRICS_COMPUTED_FLAG, true);

        foreach ($processedMetrics as $name => $processedMetric) {
            if (!$processedMetric->beforeCompute($this->report, $dataTable)) {
                continue;
            }

            foreach ($dataTable->getRows() as $row) {
                if ($row->getColumn($name) === false) { // only compute the metric if it has not been computed already
                    $computedValue = $processedMetric->compute($row);
                    if ($computedValue !== false) {
                        $row->addColumn($name, $computedValue);
                    }

                    $subtable = $row->getSubtable();
                    if (!empty($subtable)) {
                        $this->computeProcessedMetrics($subtable);
                    }
                }
            }
        }
    }

    public function applyComputeProcessedMetrics(DataTableInterface $dataTable)
    {
        $dataTable->filter(array($this, 'computeProcessedMetrics'));
    }
}