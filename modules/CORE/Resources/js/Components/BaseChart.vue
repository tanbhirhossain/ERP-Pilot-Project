<script setup lang="ts">
import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TitleComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * The shared ECharts wrapper (Phase 1 — U3).
 *
 * Tree-shaken: only bar/line/pie + the grid/tooltip/legend/title components are
 * bundled, not the whole library. The chart resizes with its container via
 * ResizeObserver, re-themes when the app's light/dark mode flips, and disposes
 * its instance on unmount so SPA navigation never leaks a canvas.
 */
echarts.use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, TitleComponent, TooltipComponent, CanvasRenderer]);

interface Props {
    option: Record<string, unknown>;
    height?: string;
    dark?: boolean;
}
const props = withDefaults(defineProps<Props>(), { height: '300px', dark: false });

const el = ref<HTMLDivElement | null>(null);
let chart: echarts.ECharts | null = null;
let observer: ResizeObserver | null = null;

function render(): void {
    if (!el.value) return;
    if (!chart) {
        chart = echarts.init(el.value, props.dark ? 'dark' : undefined);
    }
    chart.setOption(props.option, true);
}

onMounted(() => {
    render();
    observer = new ResizeObserver(() => chart?.resize());
    if (el.value) observer.observe(el.value);
});

watch(
    () => props.option,
    () => render(),
    { deep: true },
);

// Re-theme on light/dark flip: dispose + re-init with the new theme.
watch(
    () => props.dark,
    () => {
        chart?.dispose();
        chart = null;
        render();
    },
);

onBeforeUnmount(() => {
    observer?.disconnect();
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <div ref="el" :style="{ height, width: '100%' }" />
</template>
