import Chart from 'chart.js/auto';

window.Chart = Chart;
document.dispatchEvent(new CustomEvent('mathverse:charts-ready'));
