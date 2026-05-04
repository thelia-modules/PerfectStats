<?php

namespace PerfectStats\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Collection\Collection;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderCouponQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\CountryQuery;
use Thelia\Core\Translation\Translator;

class PerfectStatsService
{
    const ORDER_STATUS_NOT_PAID = 1;
    const ORDER_STATUS_PAID = 2;
    const ORDER_STATUS_PROCESSING = 3;
    const ORDER_STATUS_SENT = 4;
    const ORDER_STATUS_CANCELLED = 5;
    const ORDER_STATUS_REFUNDED = 6;

    const VALID_STATUSES = [2, 3, 4];


    public function getYearDateRange($year): array
    {
        return [
            'start' => sprintf('%d-01-01 00:00:00', $year),
            'end' => sprintf('%d-12-31 23:59:59', $year)
        ];
    }


    public function getMonthDateRange($year, $month): array
    {
        $lastDay = date('t', mktime(0, 0, 0, $month, 1, $year));
        return [
            'start' => sprintf('%d-%02d-01 00:00:00', $year, $month),
            'end' => sprintf('%d-%02d-%02d 23:59:59', $year, $month, $lastDay)
        ];
    }


    public function getDayDateRange($year, $month, $day): array
    {
        return [
            'start' => sprintf('%d-%02d-%02d 00:00:00', $year, $month, $day),
            'end'   => sprintf('%d-%02d-%02d 23:59:59', $year, $month, $day)
        ];
    }


    public function getWeekDateRange($year, $week): array
    {
        $dto = new \DateTime();
        $dto->setISODate($year, $week);
        $start = $dto->format('Y-m-d 00:00:00');
        $dto->modify('+6 days');
        $end = $dto->format('Y-m-d 23:59:59');
        return ['start' => $start, 'end' => $end];
    }


    public function getQuarterDateRange($year, $quarter): array
    {
        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth   = $startMonth + 2;
        $lastDay    = date('t', mktime(0, 0, 0, $endMonth, 1, $year));
        return [
            'start' => sprintf('%d-%02d-01 00:00:00', $year, $startMonth),
            'end'   => sprintf('%d-%02d-%02d 23:59:59', $year, $endMonth, $lastDay)
        ];
    }


    public function getGranularityForCustomRange(string $startDate, string $endDate, string $granularity): array
    {
        $startDt = new \DateTime(substr($startDate, 0, 10));
        $endDt   = new \DateTime(substr($endDate,   0, 10));

        if ($granularity === 'auto') {
            $days = (int)$startDt->diff($endDt)->days + 1;
            if ($days <= 31)   $granularity = 'day';
            elseif ($days <= 366)  $granularity = 'month';
            elseif ($days <= 1095) $granularity = 'quarter';
            else                   $granularity = 'year';
        }

        switch ($granularity) {
            case 'day':
                $labels = [];
                $d = clone $startDt;
                while ($d <= $endDt) {
                    $labels[] = $d->format('d/m/y');
                    $d->modify('+1 day');
                }
                return ['custom_day', $labels, $startDt];

            case 'month':
                $abbr = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
                $labels = [];
                $d = new \DateTime($startDt->format('Y-m-01'));
                $last = new \DateTime($endDt->format('Y-m-01'));
                while ($d <= $last) {
                    $labels[] = $abbr[(int)$d->format('n') - 1] . ' ' . $d->format('y');
                    $d->modify('+1 month');
                }
                return ['custom_month', $labels, $startDt];

            case 'quarter':
                $labels = [];
                $y = (int)$startDt->format('Y');
                $q = (int)ceil((int)$startDt->format('n') / 3);
                $ey = (int)$endDt->format('Y');
                $eq = (int)ceil((int)$endDt->format('n') / 3);
                while ($y < $ey || ($y === $ey && $q <= $eq)) {
                    $labels[] = 'T' . $q . ' ' . $y;
                    if (++$q > 4) { $q = 1; $y++; }
                }
                return ['custom_quarter', $labels, $startDt];

            default: // year
                $labels = [];
                for ($y = (int)$startDt->format('Y'); $y <= (int)$endDt->format('Y'); $y++) {
                    $labels[] = (string)$y;
                }
                return ['custom_year', $labels, $startDt];
        }
    }


    public function getGranularityForMode(string $mode, int $year, int $month, int $quarter): array
    {
        switch ($mode) {
            case 'day':
                $labelsH = [];
                for ($h = 0; $h < 24; $h++) { $labelsH[] = $h . 'h'; }
                return ['hour', $labelsH];
            case 'week':
                return ['day_of_week', ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']];
            case 'quarter':
                $startMonth  = ($quarter - 1) * 3 + 1;
                $allMonths   = $this->getTranslatedMonths();
                $qLabels     = array_slice($allMonths, $startMonth - 1, 3);
                return ['month_in_quarter', $qLabels];
            case 'month':
                $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
                return ['day_of_month', range(1, $daysInMonth)];
            default:
                return ['month', $this->getTranslatedMonths()];
        }
    }


    public function buildOrderStatsForRange($currentRange, $previousRange, $currentYear, $previousYear, $granularity, $labels, ?\DateTime $startDt = null, ?\DateTime $prevStartDt = null): array
    {
        return [
            'labels'        => $labels,
            'current_year'  => $currentYear,
            'previous_year' => $previousYear,
            'current'  => $this->aggregateOrderData($currentRange['start'],  $currentRange['end'],  $granularity, count($labels), $startDt),
            'previous' => $this->aggregateOrderData($previousRange['start'], $previousRange['end'], $granularity, count($labels), $prevStartDt ?? $startDt)
        ];
    }


    public function buildRevenueStatsForRange($currentRange, $previousRange, $currentYear, $previousYear, $granularity, $labels, ?\DateTime $startDt = null, ?\DateTime $prevStartDt = null): array
    {
        return [
            'labels'        => $labels,
            'current_year'  => $currentYear,
            'previous_year' => $previousYear,
            'current'  => $this->aggregateRevenueData($currentRange['start'],  $currentRange['end'],  $granularity, count($labels), $startDt),
            'previous' => $this->aggregateRevenueData($previousRange['start'], $previousRange['end'], $granularity, count($labels), $prevStartDt ?? $startDt)
        ];
    }

    protected function getAggregationIndex(\DateTime $date, string $granularity, ?\DateTime $startDt = null): int
    {
        switch ($granularity) {
            case 'hour':             return (int)$date->format('G');
            case 'day_of_month':     return (int)$date->format('j') - 1;
            case 'day_of_week':      return (int)$date->format('N') - 1;
            case 'month_in_quarter': return ((int)$date->format('n') - 1) % 3;
            case 'month':            return (int)$date->format('n') - 1;
            case 'custom_day':
                if (!$startDt) return 0;
                return (int)$startDt->diff($date)->days;
            case 'custom_month':
                if (!$startDt) return 0;
                return ((int)$date->format('Y') - (int)$startDt->format('Y')) * 12
                     + ((int)$date->format('n') - (int)$startDt->format('n'));
            case 'custom_quarter':
                if (!$startDt) return 0;
                $sq = (int)$startDt->format('Y') * 4 + (int)ceil((int)$startDt->format('n') / 3) - 1;
                $dq = (int)$date->format('Y')   * 4 + (int)ceil((int)$date->format('n')   / 3) - 1;
                return $dq - $sq;
            case 'custom_year':
                if (!$startDt) return 0;
                return (int)$date->format('Y') - (int)$startDt->format('Y');
            default: return 0;
        }
    }

    protected function aggregateOrderData(string $startDate, string $endDate, string $granularity, int $count, ?\DateTime $startDt = null): array
    {
        $orders   = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate,   Criteria::LESS_EQUAL)
            ->find();
        $sent      = array_fill(0, $count, 0);
        $cancelled = array_fill(0, $count, 0);
        foreach ($orders as $order) {
            $idx = $this->getAggregationIndex($order->getCreatedAt(), $granularity, $startDt);
            if ($idx >= 0 && $idx < $count) {
                if ($order->getStatusId() === self::ORDER_STATUS_SENT)      $sent[$idx]++;
                elseif ($order->getStatusId() === self::ORDER_STATUS_CANCELLED) $cancelled[$idx]++;
            }
        }
        return ['sent' => $sent, 'cancelled' => $cancelled];
    }

    protected function aggregateRevenueData(string $startDate, string $endDate, string $granularity, int $count, ?\DateTime $startDt = null): array
    {
        $orders  = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate,   Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();
        $revenue = array_fill(0, $count, 0.0);
        foreach ($orders as $order) {
            $idx = $this->getAggregationIndex($order->getCreatedAt(), $granularity, $startDt);
            if ($idx >= 0 && $idx < $count) {
                $revenue[$idx] += $order->getTotalAmount();
            }
        }
        return array_map(function($v) { return round($v, 2); }, $revenue);
    }


    public function getTranslatedMonths(): array
    {
        $translator = Translator::getInstance();

        $monthKeys = [
            'perfectstats.month.january',
            'perfectstats.month.february',
            'perfectstats.month.march',
            'perfectstats.month.april',
            'perfectstats.month.may',
            'perfectstats.month.june',
            'perfectstats.month.july',
            'perfectstats.month.august',
            'perfectstats.month.september',
            'perfectstats.month.october',
            'perfectstats.month.november',
            'perfectstats.month.december'
        ];

        $months = [];
        foreach ($monthKeys as $key) {
            $months[] = $translator->trans($key, [], 'perfectstats.bo.default');
        }

        return $months;
    }


    public function getSummary($currentYear, $previousYear, $locale = 'fr_FR'): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);

        return $this->buildSummary($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function getMonthlySummary($currentYear, $previousYear, $month, $locale = 'fr_FR'): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);

        return $this->buildSummary($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function buildSummary($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        $currentOrders = OrderQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->find();

        $previousOrders = OrderQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->find();

        $currentTotal = 0; $currentCount = 0; $currentSent = 0; $currentCancelled = 0;
        foreach ($currentOrders as $order) {
            $currentCount++;
            if (in_array($order->getStatusId(), self::VALID_STATUSES)) {
                $currentTotal += $order->getTotalAmount();
            }
            if ($order->getStatusId() === self::ORDER_STATUS_SENT) $currentSent++;
            elseif ($order->getStatusId() === self::ORDER_STATUS_CANCELLED) $currentCancelled++;
        }

        $previousTotal = 0; $previousCount = 0; $previousSent = 0; $previousCancelled = 0;
        foreach ($previousOrders as $order) {
            $previousCount++;
            if (in_array($order->getStatusId(), self::VALID_STATUSES)) {
                $previousTotal += $order->getTotalAmount();
            }
            if ($order->getStatusId() === self::ORDER_STATUS_SENT) $previousSent++;
            elseif ($order->getStatusId() === self::ORDER_STATUS_CANCELLED) $previousCancelled++;
        }

        $currentAvg = $currentCount > 0 ? $currentTotal / $currentCount : 0;
        $previousAvg = $previousCount > 0 ? $previousTotal / $previousCount : 0;
        $currentCancelRate = $currentCount > 0 ? ($currentCancelled / $currentCount) * 100 : 0;
        $previousCancelRate = $previousCount > 0 ? ($previousCancelled / $previousCount) * 100 : 0;

        $evo = function($cur, $prev) {
            return $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : 0;
        };

        return [
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => [
                'total_amount' => round($currentTotal, 2),
                'order_count' => $currentCount,
                'sent_count' => $currentSent,
                'cancelled_count' => $currentCancelled,
                'average_order' => round($currentAvg, 2),
                'cancellation_rate' => round($currentCancelRate, 1)
            ],
            'previous' => [
                'total_amount' => round($previousTotal, 2),
                'order_count' => $previousCount,
                'sent_count' => $previousSent,
                'cancelled_count' => $previousCancelled,
                'average_order' => round($previousAvg, 2),
                'cancellation_rate' => round($previousCancelRate, 1)
            ],
            'evolution' => [
                'total_amount' => $evo($currentTotal, $previousTotal),
                'order_count' => $evo($currentCount, $previousCount),
                'sent_count' => $evo($currentSent, $previousSent),
                'cancelled_count' => $evo($currentCancelled, $previousCancelled),
                'average_order' => $evo($currentAvg, $previousAvg)
            ]
        ];
    }


    public function getOrderStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);

        $months = $this->getTranslatedMonths();

        return [
            'labels' => $months,
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => $this->getMonthlyOrderData($currentRange['start'], $currentRange['end']),
            'previous' => $this->getMonthlyOrderData($previousRange['start'], $previousRange['end'])
        ];
    }


    public function getMonthlyOrderStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);

        $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $currentYear));
        $labels = range(1, $daysInMonth);

        return [
            'labels' => $labels,
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => $this->getDailyOrderData($currentRange['start'], $currentRange['end'], $daysInMonth),
            'previous' => $this->getDailyOrderData($previousRange['start'], $previousRange['end'], $daysInMonth)
        ];
    }


    protected function getMonthlyOrderData($startDate, $endDate): array
    {
        $orders = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate, Criteria::LESS_EQUAL)
            ->find();

        $sent = array_fill(0, 12, 0);
        $cancelled = array_fill(0, 12, 0);

        foreach ($orders as $order) {
            $month = (int)$order->getCreatedAt()->format('n') - 1;
            if ($order->getStatusId() === self::ORDER_STATUS_SENT) $sent[$month]++;
            elseif ($order->getStatusId() === self::ORDER_STATUS_CANCELLED) $cancelled[$month]++;
        }

        return ['sent' => $sent, 'cancelled' => $cancelled];
    }


    protected function getDailyOrderData($startDate, $endDate, $daysInMonth): array
    {
        $orders = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate, Criteria::LESS_EQUAL)
            ->find();

        $sent = array_fill(0, $daysInMonth, 0);
        $cancelled = array_fill(0, $daysInMonth, 0);

        foreach ($orders as $order) {
            $day = (int)$order->getCreatedAt()->format('j') - 1;
            if ($day < $daysInMonth) {
                if ($order->getStatusId() === self::ORDER_STATUS_SENT) $sent[$day]++;
                elseif ($order->getStatusId() === self::ORDER_STATUS_CANCELLED) $cancelled[$day]++;
            }
        }

        return ['sent' => $sent, 'cancelled' => $cancelled];
    }


    public function getMonthlyRevenueStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);

        $months = $this->getTranslatedMonths();

        return [
            'labels' => $months,
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => $this->getMonthlyRevenueData($currentRange['start'], $currentRange['end']),
            'previous' => $this->getMonthlyRevenueData($previousRange['start'], $previousRange['end'])
        ];
    }


    public function getMonthlyDailyRevenueStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);

        $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $currentYear));
        $labels = range(1, $daysInMonth);

        return [
            'labels' => $labels,
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => $this->getDailyRevenueData($currentRange['start'], $currentRange['end'], $daysInMonth),
            'previous' => $this->getDailyRevenueData($previousRange['start'], $previousRange['end'], $daysInMonth)
        ];
    }

    protected function getMonthlyRevenueData($startDate, $endDate): array
    {
        $orders = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate, Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $revenue = array_fill(0, 12, 0);
        foreach ($orders as $order) {
            $month = (int)$order->getCreatedAt()->format('n') - 1;
            $revenue[$month] += $order->getTotalAmount();
        }
        return array_map(function($v) { return round($v, 2); }, $revenue);
    }

    protected function getDailyRevenueData($startDate, $endDate, $daysInMonth): array
    {
        $orders = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate, Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $revenue = array_fill(0, $daysInMonth, 0);
        foreach ($orders as $order) {
            $day = (int)$order->getCreatedAt()->format('j') - 1;
            if ($day < $daysInMonth) {
                $revenue[$day] += $order->getTotalAmount();
            }
        }
        return array_map(function($v) { return round($v, 2); }, $revenue);
    }


    public function getPaymentMethodStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);
        return $this->buildPaymentStats($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function getMonthlyPaymentMethodStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);
        return $this->buildPaymentStats($currentRange, $previousRange, $currentYear, $previousYear);
    }

    public function buildPaymentStats($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        $currentOrders = OrderQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $previousOrders = OrderQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $currentPayments = [];
        foreach ($currentOrders as $order) {
            $name = $this->getModuleName($order->getPaymentModuleId());
            if (!isset($currentPayments[$name])) $currentPayments[$name] = ['count' => 0, 'amount' => 0];
            $currentPayments[$name]['count']++;
            $currentPayments[$name]['amount'] += $order->getTotalAmount();
        }

        $previousPayments = [];
        foreach ($previousOrders as $order) {
            $name = $this->getModuleName($order->getPaymentModuleId());
            if (!isset($previousPayments[$name])) $previousPayments[$name] = ['count' => 0, 'amount' => 0];
            $previousPayments[$name]['count']++;
            $previousPayments[$name]['amount'] += $order->getTotalAmount();
        }

        foreach ($currentPayments as &$p) { $p['amount'] = round($p['amount'], 2); }
        foreach ($previousPayments as &$p) { $p['amount'] = round($p['amount'], 2); }

        return ['current_year' => $currentYear, 'previous_year' => $previousYear, 'current' => $currentPayments, 'previous' => $previousPayments];
    }


    public function getShippingMethodStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);
        return $this->buildShippingStats($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function getMonthlyShippingMethodStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);
        return $this->buildShippingStats($currentRange, $previousRange, $currentYear, $previousYear);
    }

    public function buildShippingStats($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        $currentOrders = OrderQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $previousOrders = OrderQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $currentShipping = [];
        foreach ($currentOrders as $order) {
            $name = $this->getModuleName($order->getDeliveryModuleId());
            if (!isset($currentShipping[$name])) $currentShipping[$name] = ['count' => 0, 'amount' => 0];
            $currentShipping[$name]['count']++;
            $currentShipping[$name]['amount'] += $order->getTotalAmount();
        }

        $previousShipping = [];
        foreach ($previousOrders as $order) {
            $name = $this->getModuleName($order->getDeliveryModuleId());
            if (!isset($previousShipping[$name])) $previousShipping[$name] = ['count' => 0, 'amount' => 0];
            $previousShipping[$name]['count']++;
            $previousShipping[$name]['amount'] += $order->getTotalAmount();
        }

        foreach ($currentShipping as &$s) { $s['amount'] = round($s['amount'], 2); }
        foreach ($previousShipping as &$s) { $s['amount'] = round($s['amount'], 2); }

        return ['current_year' => $currentYear, 'previous_year' => $previousYear, 'current' => $currentShipping, 'previous' => $previousShipping];
    }


    public function getProductStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);
        return $this->buildProductStats($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function getMonthlyProductStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);
        return $this->buildProductStats($currentRange, $previousRange, $currentYear, $previousYear);
    }

    public function buildProductStats($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        $currentProducts = OrderProductQuery::create()
            ->useOrderQuery()
                ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
                ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
                ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->endUse()
            ->find();

        $previousProducts = OrderProductQuery::create()
            ->useOrderQuery()
                ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
                ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
                ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->endUse()
            ->find();

        $currentStats = [];
        foreach ($currentProducts as $product) {
            $ref = $product->getProductRef();
            if (!isset($currentStats[$ref])) $currentStats[$ref] = ['name' => $product->getTitle(), 'quantity' => 0, 'amount' => 0];
            $currentStats[$ref]['quantity'] += $product->getQuantity();
            $currentStats[$ref]['amount'] += $product->getQuantity() * $product->getPrice();
        }
        uasort($currentStats, function($a, $b) { return $b['amount'] - $a['amount']; });
        $currentTopAssoc = array_slice($currentStats, 0, 10, true);

        $previousStats = [];
        foreach ($previousProducts as $product) {
            $ref = $product->getProductRef();
            if (!isset($previousStats[$ref])) $previousStats[$ref] = ['name' => $product->getTitle(), 'quantity' => 0, 'amount' => 0];
            $previousStats[$ref]['quantity'] += $product->getQuantity();
            $previousStats[$ref]['amount'] += $product->getQuantity() * $product->getPrice();
        }
        uasort($previousStats, function($a, $b) { return $b['amount'] - $a['amount']; });
        $previousTopAssoc = array_slice($previousStats, 0, 10, true);

        $currentTop = [];
        foreach ($currentTopAssoc as $ref => $p) {
            $currentTop[] = ['ref' => $ref, 'name' => $p['name'], 'quantity' => $p['quantity'], 'amount' => round($p['amount'], 2)];
        }

        $previousTop = [];
        foreach ($previousTopAssoc as $ref => $p) {
            $previousTop[] = ['ref' => $ref, 'name' => $p['name'], 'quantity' => $p['quantity'], 'amount' => round($p['amount'], 2)];
        }

        return ['current_year' => $currentYear, 'previous_year' => $previousYear, 'top_products' => $currentTop, 'previous_top_products' => $previousTop];
    }


    public function getCustomerStats($currentYear, $previousYear): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);
        return $this->buildCustomerStats($currentRange, $previousRange, $currentYear, $previousYear);
    }


    public function getMonthlyCustomerStats($currentYear, $previousYear, $month): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);
        return $this->buildCustomerStats($currentRange, $previousRange, $currentYear, $previousYear);
    }

    public function buildCustomerStats($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        $currentOrders = OrderQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $previousOrders = OrderQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $allCustomerIds = [];
        foreach ($currentOrders  as $o) { if ($o->getCustomerId()) $allCustomerIds[$o->getCustomerId()] = true; }
        foreach ($previousOrders as $o) { if ($o->getCustomerId()) $allCustomerIds[$o->getCustomerId()] = true; }
        $customerMap = [];
        if (!empty($allCustomerIds)) {
            $customerObjects = CustomerQuery::create()->filterById(array_keys($allCustomerIds), Criteria::IN)->find();
            foreach ($customerObjects as $c) { $customerMap[$c->getId()] = $c; }
        }

        $currentCustomerStats = [];
        foreach ($currentOrders as $order) {
            $customerId = $order->getCustomerId();
            if (!$customerId) continue;
            if (!isset($currentCustomerStats[$customerId])) {
                $customer = $customerMap[$customerId] ?? null;
                $currentCustomerStats[$customerId] = [
                    'email' => $customer ? $customer->getEmail() : 'Unknown',
                    'firstname' => $customer ? $customer->getFirstname() : '',
                    'lastname' => $customer ? $customer->getLastname() : '',
                    'order_count' => 0,
                    'total_amount' => 0
                ];
            }
            $currentCustomerStats[$customerId]['order_count']++;
            $currentCustomerStats[$customerId]['total_amount'] += $order->getTotalAmount();
        }
        uasort($currentCustomerStats, function($a, $b) {
            return $b['total_amount'] > $a['total_amount'] ? 1 : ($b['total_amount'] < $a['total_amount'] ? -1 : 0);
        });

        $currentTopCustomers = array_values(array_slice($currentCustomerStats, 0, 10));

        $previousCustomerStats = [];
        foreach ($previousOrders as $order) {
            $customerId = $order->getCustomerId();
            if (!$customerId) continue;
            if (!isset($previousCustomerStats[$customerId])) {
                $customer = $customerMap[$customerId] ?? null;
                $previousCustomerStats[$customerId] = [
                    'email' => $customer ? $customer->getEmail() : 'Unknown',
                    'firstname' => $customer ? $customer->getFirstname() : '',
                    'lastname' => $customer ? $customer->getLastname() : '',
                    'order_count' => 0,
                    'total_amount' => 0
                ];
            }
            $previousCustomerStats[$customerId]['order_count']++;
            $previousCustomerStats[$customerId]['total_amount'] += $order->getTotalAmount();
        }
        uasort($previousCustomerStats, function($a, $b) {
            return $b['total_amount'] > $a['total_amount'] ? 1 : ($b['total_amount'] < $a['total_amount'] ? -1 : 0);
        });

        $previousTopCustomers = array_values(array_slice($previousCustomerStats, 0, 10));

        foreach ($currentTopCustomers as &$c) { $c['total_amount'] = round($c['total_amount'], 2); }
        foreach ($previousTopCustomers as &$c) { $c['total_amount'] = round($c['total_amount'], 2); }

        $currentNewCustomers = CustomerQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->count();

        $previousNewCustomers = CustomerQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->count();

        $newCustomersEvolution = $previousNewCustomers > 0
            ? (($currentNewCustomers - $previousNewCustomers) / $previousNewCustomers) * 100
            : 0;

        return [
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'top_customers' => $currentTopCustomers,
            'previous_top_customers' => $previousTopCustomers,
            'new_customers' => [
                'current' => $currentNewCustomers,
                'previous' => $previousNewCustomers,
                'evolution' => round($newCustomersEvolution, 1)
            ]
        ];
    }


    public function getGeographyStats($currentYear, $previousYear, $locale = 'fr_FR'): array
    {
        $currentRange = $this->getYearDateRange($currentYear);
        $previousRange = $this->getYearDateRange($previousYear);
        return $this->buildGeographyStats($currentRange, $previousRange, $currentYear, $previousYear, $locale);
    }


    public function getMonthlyGeographyStats($currentYear, $previousYear, $month, $locale = 'fr_FR'): array
    {
        $currentRange = $this->getMonthDateRange($currentYear, $month);
        $previousRange = $this->getMonthDateRange($previousYear, $month);
        return $this->buildGeographyStats($currentRange, $previousRange, $currentYear, $previousYear, $locale);
    }

    public function buildGeographyStats($currentRange, $previousRange, $currentYear, $previousYear, $locale): array
    {
        $currentOrders = OrderQuery::create()
            ->filterByCreatedAt($currentRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($currentRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $previousOrders = OrderQuery::create()
            ->filterByCreatedAt($previousRange['start'], Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($previousRange['end'], Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        $allAddressIds = [];
        foreach ($currentOrders  as $o) { if ($o->getDeliveryOrderAddressId()) $allAddressIds[$o->getDeliveryOrderAddressId()] = true; }
        foreach ($previousOrders as $o) { if ($o->getDeliveryOrderAddressId()) $allAddressIds[$o->getDeliveryOrderAddressId()] = true; }
        $addressMap = [];
        if (!empty($allAddressIds)) {
            $addresses = OrderAddressQuery::create()->filterById(array_keys($allAddressIds), Criteria::IN)->find();
            foreach ($addresses as $addr) { $addressMap[$addr->getId()] = $addr; }
        }

        $countryIds = [];
        foreach ($addressMap as $addr) { if ($addr->getCountryId()) $countryIds[$addr->getCountryId()] = true; }
        $countryMap = [];
        if (!empty($countryIds)) {
            $countries = CountryQuery::create()->filterById(array_keys($countryIds), Criteria::IN)->find();
            foreach ($countries as $country) { $countryMap[$country->getId()] = $country; }
        }

        $currentCountryStats = $this->getCountryStats($currentOrders, $locale, $addressMap, $countryMap);
        $previousCountryStats = $this->getCountryStats($previousOrders, $locale, $addressMap, $countryMap);

        foreach ($currentCountryStats as &$c) { $c['total_amount'] = round($c['total_amount'], 2); }
        foreach ($previousCountryStats as &$c) { $c['total_amount'] = round($c['total_amount'], 2); }

        return [
            'current_year' => $currentYear,
            'previous_year' => $previousYear,
            'current' => $currentCountryStats,
            'previous' => $previousCountryStats
        ];
    }


    protected function getCountryStats($orders, $locale = 'fr_FR', $addressMap = [], $countryMap = []): array
    {
        $countryStats = [];

        foreach ($orders as $order) {
            $addressId    = $order->getDeliveryOrderAddressId();
            $orderAddress = $addressId ? ($addressMap[$addressId] ?? null) : null;
            if (!$orderAddress) continue;

            $countryId = $orderAddress->getCountryId();
            if (!$countryId) continue;

            if (!isset($countryStats[$countryId])) {
                $country     = $countryMap[$countryId] ?? null;
                $countryName = 'Unknown';
                $countryCode = '';
                if ($country) {
                    $countryCode = $country->getIsoalpha2() ?: '';
                    $country->setLocale($locale);
                    $countryName = $country->getTitle();
                    if (empty($countryName)) {
                        $country->setLocale('en_US');
                        $countryName = $country->getTitle();
                    }
                }
                $countryStats[$countryId] = [
                    'name' => $countryName,
                    'code' => $countryCode,
                    'flag' => $this->getCountryFlag($countryCode),
                    'order_count' => 0,
                    'total_amount' => 0
                ];
            }
            $countryStats[$countryId]['order_count']++;
            $countryStats[$countryId]['total_amount'] += $order->getTotalAmount();
        }

        uasort($countryStats, function($a, $b) {
            return $b['total_amount'] - $a['total_amount'];
        });

        return $countryStats;
    }


    protected function getCountryFlag($countryCode): string
    {
        if (empty($countryCode) || strlen($countryCode) !== 2) {
            return '🌍';
        }

        $countryCode = strtoupper($countryCode);


        $flags = [
            'FR' => '🇫🇷', 'BE' => '🇧🇪', 'CH' => '🇨🇭', 'LU' => '🇱🇺', 'MC' => '🇲🇨',
            'ES' => '🇪🇸', 'IT' => '🇮🇹', 'DE' => '🇩🇪', 'GB' => '🇬🇧', 'UK' => '🇬🇧',
            'NL' => '🇳🇱', 'PT' => '🇵🇹', 'AT' => '🇦🇹', 'IE' => '🇮🇪', 'GR' => '🇬🇷',
            'SE' => '🇸🇪', 'NO' => '🇳🇴', 'DK' => '🇩🇰', 'FI' => '🇫🇮', 'PL' => '🇵🇱',
            'CZ' => '🇨🇿', 'HU' => '🇭🇺', 'RO' => '🇷🇴', 'BG' => '🇧🇬', 'HR' => '🇭🇷',
            'SI' => '🇸🇮', 'SK' => '🇸🇰', 'EE' => '🇪🇪', 'LV' => '🇱🇻', 'LT' => '🇱🇹',
            'US' => '🇺🇸', 'CA' => '🇨🇦', 'MX' => '🇲🇽', 'BR' => '🇧🇷', 'AR' => '🇦🇷',
            'JP' => '🇯🇵', 'CN' => '🇨🇳', 'KR' => '🇰🇷', 'IN' => '🇮🇳', 'AU' => '🇦🇺',
            'NZ' => '🇳🇿', 'ZA' => '🇿🇦', 'MA' => '🇲🇦', 'TN' => '🇹🇳', 'DZ' => '🇩🇿',
            'EG' => '🇪🇬', 'TR' => '🇹🇷', 'IL' => '🇮🇱', 'SA' => '🇸🇦', 'AE' => '🇦🇪',
            'RU' => '🇷🇺', 'UA' => '🇺🇦', 'BY' => '🇧🇾', 'RS' => '🇷🇸', 'BA' => '🇧🇦'
        ];

        if (isset($flags[$countryCode])) {
            return $flags[$countryCode];
        }


        $flag = '';
        for ($i = 0; $i < strlen($countryCode); $i++) {
            $flag .= mb_chr(ord($countryCode[$i]) - ord('A') + 0x1F1E6);
        }

        return $flag;
    }


    protected function getModuleName($moduleId): string
    {
        if (!$moduleId) return 'Inconnu';

        static $moduleCache = [];

        if (isset($moduleCache[$moduleId])) {
            return $moduleCache[$moduleId];
        }

        $module = ModuleQuery::create()
            ->filterById($moduleId)
            ->findOne();

        if (!$module) {
            $moduleCache[$moduleId] = 'Module #' . $moduleId;
            return $moduleCache[$moduleId];
        }

        $locale = 'fr_FR';
        try {
            $locale = \Thelia\Core\Translation\Translator::getInstance()->getLocale();
        } catch (\Exception $e) {

        }

        $module->setLocale($locale);
        $title = $module->getTitle();

        if (empty($title)) {
            $module->setLocale('en_US');
            $title = $module->getTitle();
        }

        if (empty($title)) {
            $title = $module->getCode();
        }

        $moduleCache[$moduleId] = $title;
        return $title;
    }


    public function buildBrandStats($currentRange, $previousRange, $currentYear, $previousYear, $locale = 'fr_FR'): array
    {
        $currentProducts  = $this->getOrderProductsInRange($currentRange['start'],  $currentRange['end']);
        $previousProducts = $this->getOrderProductsInRange($previousRange['start'], $previousRange['end']);

        return [
            'current_year'  => $currentYear,
            'previous_year' => $previousYear,
            'current'  => $this->aggregateBrandData($currentProducts,  $locale),
            'previous' => $this->aggregateBrandData($previousProducts, $locale)
        ];
    }

    protected function getOrderProductsInRange(string $startDate, string $endDate): array|Collection
    {
        return OrderProductQuery::create()
            ->useOrderQuery()
                ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
                ->filterByCreatedAt($endDate,   Criteria::LESS_EQUAL)
                ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->endUse()
            ->find();
    }

    protected function aggregateBrandData($orderProducts, $locale): array
    {

        $refs = [];
        foreach ($orderProducts as $op) {
            $r = $op->getProductRef();
            if ($r) $refs[$r] = true;
        }
        $refs = array_keys($refs);

        $brandByRef = [];
        if (!empty($refs)) {
            $products = ProductQuery::create()->filterByRef($refs, Criteria::IN)->find();
            foreach ($products as $product) {
                $brand = $product->getBrand();
                if ($brand) {
                    $brand->setLocale($locale);
                    $brandTitle = $brand->getTitle();
                    if (empty($brandTitle)) {
                        $brand->setLocale('en_US');
                        $brandTitle = $brand->getTitle();
                    }
                    $brandByRef[$product->getRef()] = $brandTitle ?: 'Sans marque';
                } else {
                    $brandByRef[$product->getRef()] = 'Sans marque';
                }
            }
        }

        $stats = [];
        foreach ($orderProducts as $op) {
            $brandName = $brandByRef[$op->getProductRef()] ?? 'Sans marque';
            if (!isset($stats[$brandName])) {
                $stats[$brandName] = ['name' => $brandName, 'quantity' => 0, 'amount' => 0.0, 'order_count' => 0];
            }
            $stats[$brandName]['quantity']    += $op->getQuantity();
            $stats[$brandName]['amount']      += $op->getQuantity() * $op->getPrice();
            $stats[$brandName]['order_count'] += 1;
        }

        uasort($stats, function($a, $b) { return $b['amount'] <=> $a['amount']; });

        $result = [];
        foreach ($stats as $data) {
            $result[] = ['name' => $data['name'], 'quantity' => $data['quantity'], 'amount' => round($data['amount'], 2), 'order_count' => $data['order_count']];
        }
        return $result;
    }


    public function buildCouponStats($currentRange, $previousRange, $currentYear, $previousYear): array
    {
        return [
            'current_year'  => $currentYear,
            'previous_year' => $previousYear,
            'current'  => $this->aggregateCouponData($currentRange['start'],  $currentRange['end']),
            'previous' => $this->aggregateCouponData($previousRange['start'], $previousRange['end'])
        ];
    }

    protected function aggregateCouponData(string $startDate, string $endDate): array
    {

        $orders = OrderQuery::create()
            ->filterByCreatedAt($startDate, Criteria::GREATER_EQUAL)
            ->filterByCreatedAt($endDate,   Criteria::LESS_EQUAL)
            ->filterByStatusId(self::VALID_STATUSES, Criteria::IN)
            ->find();

        if ($orders->count() === 0) {
            return [];
        }

        $orderIds = [];
        $amountByOrderId = [];
        foreach ($orders as $order) {
            $orderIds[] = $order->getId();
            $amountByOrderId[$order->getId()] = $order->getTotalAmount();
        }

        $coupons = OrderCouponQuery::create()
            ->filterByOrderId($orderIds, Criteria::IN)
            ->find();

        $stats = [];
        foreach ($coupons as $coupon) {
            $code = $coupon->getCode();
            if (!isset($stats[$code])) {
                $stats[$code] = [
                    'code'          => $code,
                    'title'         => $coupon->getTitle(),
                    'usage_count'   => 0,
                    'total_discount'=> 0.0,
                    'total_orders'  => 0.0
                ];
            }
            $stats[$code]['usage_count']++;
            $stats[$code]['total_discount'] += $coupon->getAmount();
            $stats[$code]['total_orders']   += $amountByOrderId[$coupon->getOrderId()] ?? 0;
        }

        uasort($stats, function($a, $b) { return $b['usage_count'] <=> $a['usage_count']; });

        $result = [];
        foreach ($stats as $data) {
            $result[] = [
                'code'          => $data['code'],
                'title'         => $data['title'],
                'usage_count'   => $data['usage_count'],
                'total_discount'=> round($data['total_discount'], 2),
                'total_orders'  => round($data['total_orders'],   2)
            ];
        }
        return $result;
    }
}
