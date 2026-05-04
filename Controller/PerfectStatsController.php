<?php

namespace PerfectStats\Controller;

use PerfectStats\Service\PerfectStatsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;

class PerfectStatsController extends BaseAdminController
{
    private $monthKeys = [
        1 => 'perfectstats.month.january',   2 => 'perfectstats.month.february',
        3 => 'perfectstats.month.march',      4 => 'perfectstats.month.april',
        5 => 'perfectstats.month.may',        6 => 'perfectstats.month.june',
        7 => 'perfectstats.month.july',       8 => 'perfectstats.month.august',
        9 => 'perfectstats.month.september', 10 => 'perfectstats.month.october',
        11 => 'perfectstats.month.november', 12 => 'perfectstats.month.december'
    ];

    public function __construct(protected PerfectStatsService $perfectStatsService, protected LoggerInterface $logger)
    {}

    private function getMonthName($month): string
    {
        $key = $this->monthKeys[$month] ?? 'perfectstats.month.january';
        return $this->getTranslator()->trans($key, [], 'perfectstats.bo.default');
    }

    protected function getCurrentLocale(): string
    {
        try {
            $session = $this->getRequest()->getSession();
            if ($session) {
                $lang = $session->getLang();
                if ($lang) return $lang->getLocale();
            }
        } catch (\Exception $e) {}
        return 'fr_FR';
    }


    private function getDateRanges(): array
    {
        $now     = new \DateTime();
        $mode    = $this->getRequest()->query->get('mode', 'month');
        $year    = (int)($this->getRequest()->query->get('year', $now->format('Y')));
        $prevYear = $year - 1;

        switch ($mode) {
            case 'day':
                $month = (int)($this->getRequest()->query->get('month', $now->format('n')));
                $day   = (int)($this->getRequest()->query->get('day',   $now->format('j')));
                $cur  = $this->perfectStatsService->getDayDateRange($year,     $month, $day);
                $prev = $this->perfectStatsService->getDayDateRange($prevYear, $month, $day);
                break;
            case 'week':
                $week = (int)($this->getRequest()->query->get('week', (int)$now->format('W')));
                $cur  = $this->perfectStatsService->getWeekDateRange($year,     $week);
                $prev = $this->perfectStatsService->getWeekDateRange($prevYear, $week);
                break;
            case 'quarter':
                $quarter = (int)($this->getRequest()->query->get('quarter', (int)ceil((int)$now->format('n') / 3)));
                $cur  = $this->perfectStatsService->getQuarterDateRange($year,     $quarter);
                $prev = $this->perfectStatsService->getQuarterDateRange($prevYear, $quarter);
                break;
            case 'month':
                $month = (int)($this->getRequest()->query->get('month', $now->format('n')));
                $cur  = $this->perfectStatsService->getMonthDateRange($year,     $month);
                $prev = $this->perfectStatsService->getMonthDateRange($prevYear, $month);
                break;
            case 'custom':
                $startStr  = $this->getRequest()->query->get('start', '');
                $endStr    = $this->getRequest()->query->get('end',   '');
                $noCompare = (bool)$this->getRequest()->query->get('no_compare', 0);
                if (!$startStr || !$endStr) {
                    $month = (int)$now->format('n');
                    $cur  = $this->perfectStatsService->getMonthDateRange($year, $month);
                    $prev = $this->perfectStatsService->getMonthDateRange($prevYear, $month);
                    break;
                }
                $startDt = new \DateTime($startStr . ' 00:00:00');
                $endDt   = new \DateTime($endStr   . ' 23:59:59');
                $cur     = ['start' => $startDt->format('Y-m-d 00:00:00'), 'end' => $endDt->format('Y-m-d 23:59:59')];
                $year    = (int)$endDt->format('Y');
                if ($noCompare) {
                    $prev     = ['start' => '1970-01-01 00:00:00', 'end' => '1970-01-01 00:00:00'];
                    $prevYear = $year - 1;
                } else {
                    $prevStartStr = $this->getRequest()->query->get('prev_start', '');
                    $prevEndStr   = $this->getRequest()->query->get('prev_end',   '');
                    if ($prevStartStr && $prevEndStr) {
                        $prevStartDt = new \DateTime($prevStartStr);
                        $prevEndDt   = new \DateTime($prevEndStr);
                    } else {
                        $prevStartDt = clone $startDt;
                        $prevStartDt->modify('-1 year');
                        $prevEndDt = clone $endDt;
                        $prevEndDt->modify('-1 year');
                    }
                    $prevEndDt->setTime(23, 59, 59);
                    $prev     = ['start' => $prevStartDt->format('Y-m-d 00:00:00'), 'end' => $prevEndDt->format('Y-m-d H:i:s')];
                    $prevYear = (int)$prevStartDt->format('Y');
                }
                break;
            default:
                $cur  = $this->perfectStatsService->getYearDateRange($year);
                $prev = $this->perfectStatsService->getYearDateRange($prevYear);
        }

        return [$cur, $prev, $year, $prevYear];
    }

    private function errorResponse(\Exception $e, string $action): Response
    {
        $this->logger->error('PerfectStats ' . $action . ' error: ' . $e->getMessage(), ['exception' => $e]);
        return $this->jsonResponse(json_encode(['error' => true, 'message' => 'Une erreur interne est survenue.']), 500);
    }

    #[Route("/admin/module/perfectstats", name: "perfectstats.dashboard", methods: ["GET"])]
    public function dashboardAction(): Response
    {
        $now          = new \DateTime();
        $currentYear  = (int)$now->format('Y');
        $previousYear = $currentYear - 1;
        $currentMonth = (int)$now->format('n');

        return $this->render('perfectstats-dashboard', [
            'current_year'       => $currentYear,
            'previous_year'      => $previousYear,
            'current_month'      => $currentMonth,
            'current_month_name' => $this->getMonthName($currentMonth),
            'current_week'       => (int)$now->format('W'),
            'current_quarter'    => (int)ceil($currentMonth / 3),
            'current_locale'     => $this->getCurrentLocale()
        ]);
    }

    #[Route("/admin/module/perfectstats/summary", name: "perfectstats.summary", methods: ["GET"])]
    public function getSummaryAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildSummary($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getSummary'); }
    }

    #[Route("/admin/module/perfectstats/orders", name: "perfectstats.orders", methods: ["GET"])]
    public function getOrderStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            $now       = new \DateTime();
            $mode      = $this->getRequest()->query->get('mode', 'month');
            $month     = (int)($this->getRequest()->query->get('month',   $now->format('n')));
            $quarter   = (int)($this->getRequest()->query->get('quarter', (int)ceil((int)$now->format('n') / 3)));
            if ($mode === 'custom') {
                $granReq = $this->getRequest()->query->get('granularity', 'auto');
                [$granularity, $labels, $startDt] = $this->perfectStatsService->getGranularityForCustomRange($cur['start'], $cur['end'], $granReq);
                $prevStartDt = new \DateTime(substr($prev['start'], 0, 10));
                [, $prevLabels] = $this->perfectStatsService->getGranularityForCustomRange($prev['start'], $prev['end'], str_replace('custom_', '', $granularity));
            } else {
                [$granularity, $labels] = $this->perfectStatsService->getGranularityForMode($mode, $y, $month, $quarter);
                $startDt = null;
                $prevStartDt = null;
                $prevLabels = null;
            }
            $result = $this->perfectStatsService->buildOrderStatsForRange($cur, $prev, $y, $py, $granularity, $labels, $startDt, $prevStartDt);
            if ($prevLabels !== null) { $result['prev_labels'] = $prevLabels; }
            return $this->jsonResponse(json_encode($result));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getOrderStats'); }
    }

    #[Route("/admin/module/perfectstats/revenue", name: "perfectstats.revenue", methods: ["GET"])]
    public function getRevenueStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            $now       = new \DateTime();
            $mode      = $this->getRequest()->query->get('mode', 'month');
            $month     = (int)($this->getRequest()->query->get('month',   $now->format('n')));
            $quarter   = (int)($this->getRequest()->query->get('quarter', (int)ceil((int)$now->format('n') / 3)));
            if ($mode === 'custom') {
                $granReq = $this->getRequest()->query->get('granularity', 'auto');
                [$granularity, $labels, $startDt] = $this->perfectStatsService->getGranularityForCustomRange($cur['start'], $cur['end'], $granReq);
                $prevStartDt = new \DateTime(substr($prev['start'], 0, 10));
                [, $prevLabels] = $this->perfectStatsService->getGranularityForCustomRange($prev['start'], $prev['end'], str_replace('custom_', '', $granularity));
            } else {
                [$granularity, $labels] = $this->perfectStatsService->getGranularityForMode($mode, $y, $month, $quarter);
                $startDt = null;
                $prevStartDt = null;
                $prevLabels = null;
            }
            $result = $this->perfectStatsService->buildRevenueStatsForRange($cur, $prev, $y, $py, $granularity, $labels, $startDt, $prevStartDt);
            if ($prevLabels !== null) { $result['prev_labels'] = $prevLabels; }
            return $this->jsonResponse(json_encode($result));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getRevenueStats'); }
    }

    #[Route("/admin/module/perfectstats/payments", name: "perfectstats.payments", methods: ["GET"])]
    public function getPaymentStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildPaymentStats($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getPaymentStats'); }
    }

    #[Route("/admin/module/perfectstats/shipping", name: "perfectstats.shipping", methods: ["GET"])]
    public function getShippingStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildShippingStats($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getShippingStats'); }
    }

    #[Route("/admin/module/perfectstats/products", name: "perfectstats.products", methods: ["GET"])]
    public function getProductStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildProductStats($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getProductStats'); }
    }

    #[Route("/admin/module/perfectstats/customers", name: "perfectstats.customers", methods: ["GET"])]
    public function getCustomerStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildCustomerStats($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getCustomerStats'); }
    }

    #[Route("/admin/module/perfectstats/geography", name: "perfectstats.geography", methods: ["GET"])]
    public function getGeographyStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildGeographyStats($cur, $prev, $y, $py, $this->getCurrentLocale())
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getGeographyStats'); }
    }

    #[Route("/admin/module/perfectstats/brands", name: "perfectstats.brands", methods: ["GET"])]
    public function getBrandStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildBrandStats($cur, $prev, $y, $py, $this->getCurrentLocale())
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getBrandStats'); }
    }

    #[Route("/admin/module/perfectstats/coupons", name: "perfectstats.coupons", methods: ["GET"])]
    public function getCouponStatsAction(): Response
    {
        try {
            [$cur, $prev, $y, $py] = $this->getDateRanges();
            return $this->jsonResponse(json_encode(
                $this->perfectStatsService->buildCouponStats($cur, $prev, $y, $py)
            ));
        } catch (\Exception $e) { return $this->errorResponse($e, 'getCouponStats'); }
    }
}
