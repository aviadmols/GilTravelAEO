<?php

namespace App\Http\Controllers;

use App\Reports\Dashboard;
use App\Reports\ReportPeriod;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard)
    {
        $period = ReportPeriod::fromKey($request->query('period'));

        if ($this->showLoader($request, $dashboard, $period)) {
            return view('report-loading', ['period' => $period]);
        }

        return view('report', [
            'period' => $period,
            'sections' => $dashboard->build($period, $request->boolean('refresh')),
        ]);
    }
}
