/**
 * Angeo AEO Audit — Score Trend chart.
 *
 * CSP-safe replacement (3.1.0) for the former inline <script> block:
 *  - loaded via x-magento-init, no inline JS in the template
 *  - Chart.js resolved through RequireJS from the locally bundled copy
 *  - all server data arrives via the x-magento-init JSON config
 */
define([
    'jquery',
    'angeoChart'
], function ($, Chart) {
    'use strict';

    var PALETTE = [
        '#2563eb', '#059669', '#d97706', '#7c3aed',
        '#db2777', '#0891b2', '#65a30d', '#ea580c'
    ];

    return function (config) {
        var historyUrl = String(config.historyUrl || '');
        var chart = null;

        function fetchHistory(store, days, callback) {
            $.ajax({
                url: historyUrl,
                type: 'GET',
                dataType: 'json',
                data: {
                    store: store,
                    days: days
                },
                showLoader: false
            }).done(function (data) {
                callback(data && typeof data === 'object' ? data : {success: false});
            }).fail(function () {
                callback({success: false});
            });
        }

        function renderChart(data) {
            var canvas = document.getElementById('angeo-trend-chart'),
                loading = document.getElementById('angeo-chart-loading'),
                subtitle = document.getElementById('angeo-chart-subtitle'),
                allDates = {},
                labels,
                datasets = [],
                colorIdx = 0,
                displayLabels,
                totalPoints = 0;

            if (!canvas || !loading) {
                return;
            }

            if (!data.success || !data.data || Object.keys(data.data).length === 0) {
                loading.textContent = 'No data for selected range.';
                loading.style.display = 'block';
                canvas.style.display = 'none';
                return;
            }

            loading.style.display = 'none';
            canvas.style.display = 'block';

            Object.keys(data.data).forEach(function (storeCode) {
                data.data[storeCode].forEach(function (p) {
                    allDates[String(p.date).substring(0, 16)] = true;
                });
            });
            labels = Object.keys(allDates).sort();

            Object.keys(data.data).forEach(function (storeCode) {
                var points = data.data[storeCode],
                    color = PALETTE[colorIdx % PALETTE.length],
                    scoreByDate = {},
                    values;

                colorIdx++;
                totalPoints += points.length;

                points.forEach(function (p) {
                    scoreByDate[String(p.date).substring(0, 16)] = p.score;
                });

                values = labels.map(function (d) {
                    return scoreByDate[d] !== undefined ? scoreByDate[d] : null;
                });

                datasets.push({
                    label: storeCode,
                    data: values,
                    borderColor: color,
                    backgroundColor: color + '18',
                    pointBackgroundColor: color,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2.5,
                    tension: 0.3,
                    spanGaps: true,
                    fill: datasets.length === 0
                });
            });

            displayLabels = labels.map(function (d) {
                return new Date(d).toLocaleDateString(
                    'en-US',
                    {month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'}
                );
            });

            if (subtitle) {
                subtitle.textContent = totalPoints
                    + ' audit' + (totalPoints !== 1 ? 's' : '')
                    + ' · last ' + data.days + ' days';
            }

            if (chart) {
                chart.destroy();
                chart = null;
            }

            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: displayLabels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: datasets.length > 1,
                            position: 'top',
                            labels: {font: {size: 12}, boxWidth: 14, padding: 16}
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {color: '#f3f4f6'},
                            ticks: {font: {size: 11}, color: '#9ca3af', maxTicksLimit: 10, maxRotation: 30}
                        },
                        y: {
                            min: 0,
                            max: 100,
                            grid: {color: '#f3f4f6'},
                            ticks: {
                                font: {size: 11},
                                color: '#9ca3af',
                                callback: function (v) {
                                    return v + '%';
                                },
                                stepSize: 20
                            },
                            afterDataLimits: function (axis) {
                                axis.max = 100;
                            }
                        }
                    }
                },
                plugins: [{
                    id: 'referenceLines',
                    afterDraw: function (ch) {
                        var ctx2 = ch.ctx,
                            yAxis = ch.scales.y,
                            xLeft = ch.chartArea.left,
                            xRight = ch.chartArea.right;

                        [[85, '#059669', 'Excellent'], [65, '#d97706', 'Good']].forEach(function (ref) {
                            var y = yAxis.getPixelForValue(ref[0]);

                            ctx2.save();
                            ctx2.setLineDash([6, 4]);
                            ctx2.strokeStyle = ref[1] + '60';
                            ctx2.lineWidth = 1.5;
                            ctx2.beginPath();
                            ctx2.moveTo(xLeft, y);
                            ctx2.lineTo(xRight, y);
                            ctx2.stroke();
                            ctx2.fillStyle = ref[1];
                            ctx2.font = '10px sans-serif';
                            ctx2.textAlign = 'right';
                            ctx2.fillText(ref[2] + ' ' + ref[0] + '%', xRight - 4, y - 4);
                            ctx2.restore();
                        });
                    }
                }]
            });
        }

        function reload() {
            var store = $('#angeo-store-select').val() || '',
                days = $('#angeo-days-select').val() || '30',
                loading = document.getElementById('angeo-chart-loading'),
                canvas = document.getElementById('angeo-trend-chart');

            if (!loading) {
                return;
            }

            loading.style.display = 'block';
            loading.textContent = 'Loading chart data...';

            if (canvas) {
                canvas.style.display = 'none';
            }

            fetchHistory(store, days, renderChart);
        }

        $('#angeo-store-select').on('change', reload);
        $('#angeo-days-select').on('change', reload);
        $('#angeo-refresh-btn').on('click', function (e) {
            e.preventDefault();
            reload();
        });

        reload();
    };
});
