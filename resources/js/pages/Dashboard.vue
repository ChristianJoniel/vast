<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue';
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    componentToString
    
} from '@/components/ui/chart';
import type {ChartConfig} from '@/components/ui/chart';
import { dashboard } from '@/routes';

interface ReconciliationRow {
    location_id: string;
    report_date: string;
    expected: string;
    actual: string;
    diff: string;
    status: 'match' | 'mismatch' | 'missing_actual' | 'missing_expected';
}

interface DailyByLocationRow {
    location_id: string;
    report_date: string;
    net_revenue: string;
}

interface Totals {
    imported: string;
    expected: string;
    diff: string;
    mismatches_count: number;
}

const props = defineProps<{
    revenueData: {
        totals: Totals;
        daily_by_location: DailyByLocationRow[];
        reconciliation: ReconciliationRow[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

// --- Reconciliation Variance chart ---
type ReconChartRow = { label: string; actual: number; expected: number };

const reconChartData = computed<ReconChartRow[]>(() =>
    props.revenueData.reconciliation.map((r) => ({
        label: `${r.location_id} · ${r.report_date.slice(5)}`,
        actual: Number(r.actual),
        expected: Number(r.expected),
    })),
);

const reconConfig = {
    actual: { label: 'Actual', color: 'var(--chart-1)' },
    expected: { label: 'Expected', color: 'var(--chart-2)' },
} satisfies ChartConfig;

// --- Total Revenue by Location chart ---
type LocationTotalRow = { location: string; total: number };

const totalByLocation = computed<LocationTotalRow[]>(() => {
    const byLoc: Record<string, number> = {};

    for (const row of props.revenueData.daily_by_location) {
        byLoc[row.location_id] =
            (byLoc[row.location_id] ?? 0) + Number(row.net_revenue);
    }

    return Object.entries(byLoc)
        .map(([location, total]) => ({ location, total }))
        .sort((a, b) => a.location.localeCompare(b.location));
});

const totalConfig = {
    total: { label: 'Net Revenue', color: 'var(--chart-3)' },
} satisfies ChartConfig;

// --- Formatters ---
const formatMoney = (s: string): string => {
    const n = Number(s);

    return `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const diffClass = computed(() =>
    props.revenueData.totals.diff.startsWith('-')
        ? 'text-red-600 dark:text-red-400'
        : 'text-emerald-600 dark:text-emerald-400',
);
</script>

<template>
    <Head title="Revenue Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <!-- KPI cards -->
        <div class="grid gap-4 md:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Imported revenue</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ formatMoney(revenueData.totals.imported) }}
                    </CardTitle>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Expected revenue</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ formatMoney(revenueData.totals.expected) }}
                    </CardTitle>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Net variance</CardDescription>
                    <CardTitle class="text-2xl" :class="diffClass">
                        {{ formatMoney(revenueData.totals.diff) }}
                    </CardTitle>
                </CardHeader>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Mismatches</CardDescription>
                    <CardTitle
                        class="text-2xl"
                        :class="
                            revenueData.totals.mismatches_count > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ revenueData.totals.mismatches_count }}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>

        <!-- Reconciliation Variance chart -->
        <Card>
            <CardHeader>
                <CardTitle>Reconciliation Variance</CardTitle>
                <CardDescription>
                    Actual vs expected net revenue, per location and date. Any
                    visible gap between the bars flags a discrepancy.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer :config="reconConfig" class="h-[360px] w-full">
                    <VisXYContainer :data="reconChartData" :height="320">
                        <VisGroupedBar
                            :x="(_d: ReconChartRow, i: number) => i"
                            :y="[
                                (d: ReconChartRow) => d.actual,
                                (d: ReconChartRow) => d.expected,
                            ]"
                            :color="[
                                reconConfig.actual.color,
                                reconConfig.expected.color,
                            ]"
                        />
                        <VisAxis
                            type="x"
                            :tick-format="
                                (i: number) => reconChartData[i]?.label ?? ''
                            "
                            :num-ticks="reconChartData.length"
                            :tick-line="false"
                            :domain-line="false"
                        />
                        <VisAxis
                            type="y"
                            :tick-format="
                                (d: number) => '$' + d.toLocaleString()
                            "
                            :tick-line="false"
                            :domain-line="false"
                            :grid-line="true"
                        />
                        <ChartTooltip
                            :template="
                                componentToString(
                                    reconConfig,
                                    ChartTooltipContent,
                                )
                            "
                        />
                    </VisXYContainer>
                </ChartContainer>
            </CardContent>
        </Card>

        <!-- Total Revenue by Location chart -->
        <Card>
            <CardHeader>
                <CardTitle>Total Revenue by Location</CardTitle>
                <CardDescription>
                    Sum of imported net revenue across all report dates.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer :config="totalConfig" class="h-[300px] w-full">
                    <VisXYContainer :data="totalByLocation" :height="260">
                        <VisGroupedBar
                            :x="(_d: LocationTotalRow, i: number) => i"
                            :y="[(d: LocationTotalRow) => d.total]"
                            :color="[totalConfig.total.color]"
                        />
                        <VisAxis
                            type="x"
                            :tick-format="
                                (i: number) =>
                                    totalByLocation[i]?.location ?? ''
                            "
                            :num-ticks="totalByLocation.length"
                            :tick-line="false"
                            :domain-line="false"
                        />
                        <VisAxis
                            type="y"
                            :tick-format="
                                (d: number) => '$' + d.toLocaleString()
                            "
                            :tick-line="false"
                            :domain-line="false"
                            :grid-line="true"
                        />
                        <ChartTooltip
                            :template="
                                componentToString(
                                    totalConfig,
                                    ChartTooltipContent,
                                )
                            "
                        />
                    </VisXYContainer>
                </ChartContainer>
            </CardContent>
        </Card>
    </div>
</template>
