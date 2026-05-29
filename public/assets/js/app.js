const drawDashboard = () => {
  if (!window.dashboardData) return;
  if (typeof Chart === 'undefined') {
    const grid = document.querySelector('.charts-grid');
    if (grid) { grid.insertAdjacentHTML('afterbegin', '<div class="alert error">Unable to load dashboard charts. Please refresh the page.</div>'); }
    return;
  }

  const trend = Array.isArray(window.dashboardData.trend)
    ? window.dashboardData.trend.map(row => ({
        month_key: String(row.month_key || ''),
        expected: Number(row.expected || 0),
        paid: Number(row.paid || 0)
      }))
    : [];
  const distribution = window.dashboardData.distribution && typeof window.dashboardData.distribution === 'object'
    ? window.dashboardData.distribution
    : { paid_total: 0, arrears_total: 0 };
  const formatKsh = value => `KSh ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  const now = new Date();
  const currentMonthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const filteredTrend = trend.filter(row => {
    const monthKey = String(row.month_key || '');
    if (!/^\d{4}-\d{2}$/.test(monthKey)) return false;
    return monthKey <= currentMonthKey;
  });

  const labels = filteredTrend.map(row => row.month_key);
  const paid = filteredTrend.map(row => row.paid);

  const incomeTrendCanvas = document.getElementById('incomeTrend');
  if (incomeTrendCanvas) {
    new Chart(incomeTrendCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{ label: 'Revenue Collected (KSH)', data: paid, borderColor: '#198754', tension: 0.3 }]
      },
      options: {
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            },
            ticks: {
              callback: value => formatKsh(value)
            }
          },
          x: {
            grid: {
              display: true,
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`,
              afterBody: items => {
                if (!items.length) return '';
                const idx = items[0].dataIndex;
                const row = filteredTrend[idx] || { expected: 0, paid: 0 };
                return [`Expected: ${formatKsh(row.expected)}`, `Paid: ${formatKsh(row.paid)}`];
              }
            }
          }
        }
      }
    });
  }

  const distributionTotals = {
    Paid: Number(distribution.paid_total || 0),
    Arrears: Number(distribution.arrears_total || 0)
  };

  const statusPieCanvas = document.getElementById('statusPie');
  if (statusPieCanvas) {
    new Chart(statusPieCanvas, {
      type: 'pie',
      data: {
        labels: ['Paid', 'Arrears (Unpaid)'],
        datasets: [{ data: [distributionTotals.Paid, distributionTotals.Arrears], backgroundColor: ['#198754', '#dc3545'] }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1,
        plugins: {
          legend: {
            position: 'bottom'
          },
          tooltip: {
            callbacks: {
              label: context => `${context.label}: ${formatKsh(context.parsed)}`
            }
          }
        }
      }
    });
  }
};
const initDashboardFilters = () => {
  const form = document.getElementById('dashboardFilters');
  if (!form) return;
  const viewSelect = form.querySelector('select[name="view"]');
  const referenceSelect = form.querySelector('select[name="month"]');
  const yearInput = form.querySelector('input[name="year"]');
  if (!viewSelect || !referenceSelect || !yearInput) return;

  const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const buildOptions = mode => {
    const selectedYear = Number(yearInput.value || new Date().getFullYear());
    const prevValue = referenceSelect.value;
    referenceSelect.innerHTML = '';

    // "All" option — shows year-to-date KPIs
    const allOpt = document.createElement('option');
    allOpt.value = 'all';
    allOpt.textContent = mode === 'quarterly' ? 'All Quarters' : 'All Months';
    referenceSelect.appendChild(allOpt);

    if (mode === 'quarterly') {
      for (let quarter = 1; quarter <= 4; quarter += 1) {
        const option = document.createElement('option');
        option.value = `${selectedYear}-${String(((quarter - 1) * 3) + 1).padStart(2, '0')}`;
        option.textContent = `Q${quarter}`;
        referenceSelect.appendChild(option);
      }
    } else {
      monthNames.forEach((label, idx) => {
        const option = document.createElement('option');
        option.value = `${selectedYear}-${String(idx + 1).padStart(2, '0')}`;
        option.textContent = label;
        referenceSelect.appendChild(option);
      });
    }

    // Restore previous selection when it still exists (preserves 'all' across year changes)
    const prevExists = [...referenceSelect.options].some(o => o.value === prevValue);
    referenceSelect.value = prevExists ? prevValue : referenceSelect.options[0].value;
  };

  viewSelect.addEventListener('change', () => {
    buildOptions(viewSelect.value);
  });

  yearInput.addEventListener('change', () => buildOptions(viewSelect.value));
};

let monthlyBudgetChart = null;
let quarterlyBudgetChart = null;

const drawBudget = () => {
  if (!window.budgetData || typeof Chart === 'undefined') return;

  const m = Array.isArray(window.budgetData.monthly)
    ? window.budgetData.monthly.map(x => ({
        month_key: String(x.month_key || ''),
        expected: Number(x.expected || 0),
        paid: Number(x.paid || 0),
        outstanding: Number(x.outstanding || 0)
      }))
    : [];
  const currentQuarter = Number(window.budgetData.currentQuarter || 4);
  const selectedYear = Number(window.budgetData.selectedYear || 0);
  const currentYear = Number(window.budgetData.currentYear || 0);
  const q = Array.isArray(window.budgetData.quarterly)
    ? window.budgetData.quarterly
        .map(x => ({
          quarter_label: String(x.quarter_label || ''),
          expected: Number(x.expected || 0),
          paid: Number(x.paid || 0)
        }))
        .filter(row => {
          const quarterNumber = Number(String(row.quarter_label || '').replace('Q', ''));
          if (!Number.isFinite(quarterNumber)) return false;
          if (selectedYear !== currentYear) return true;
          return quarterNumber <= currentQuarter;
        })
    : [];
  const formatKsh = value => `KSh ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  const monthlyCanvas = document.getElementById('monthlyBudget');
  if (monthlyCanvas) {
    if (monthlyBudgetChart) monthlyBudgetChart.destroy();
    monthlyBudgetChart = new Chart(monthlyCanvas, {
      type: 'bar',
      data: {
        labels: m.map(x => x.month_key),
        datasets: [
          { label: 'Expected', data: m.map(x => x.expected), backgroundColor: '#93c5fd' },
          { label: 'Paid', data: m.map(x => x.paid), backgroundColor: '#198754' },
          { label: 'Outstanding', data: m.map(x => x.outstanding), backgroundColor: '#dc3545' }
        ]
      },
      options: {
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            },
            ticks: {
              callback: value => formatKsh(value)
            }
          },
          x: {
            grid: {
              display: true,
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`,
              afterBody: items => {
                if (!items.length) return '';
                const idx = items[0].dataIndex;
                const row = m[idx] || { expected: 0, paid: 0, outstanding: 0 };
                return [`Expected: ${formatKsh(row.expected)}`, `Paid: ${formatKsh(row.paid)}`, `Outstanding: ${formatKsh(row.outstanding)}`];
              }
            }
          }
        }
      }
    });
  }

  const quarterlyCanvas = document.getElementById('quarterlyBudget');
  if (quarterlyCanvas) {
    if (quarterlyBudgetChart) quarterlyBudgetChart.destroy();
    quarterlyBudgetChart = new Chart(quarterlyCanvas, {
      type: 'line',
      data: {
        labels: q.map(x => x.quarter_label),
        datasets: [
          { label: 'Expected', data: q.map(x => x.expected), borderColor: '#3b82f6' },
          { label: 'Paid', data: q.map(x => x.paid), borderColor: '#198754' }
        ]
      },
      options: {
        scales: {
          y: {
            beginAtZero: true,
            grid: {
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            },
            ticks: {
              callback: value => formatKsh(value)
            }
          },
          x: {
            grid: {
              display: true,
              color: 'rgba(100, 116, 139, 0.22)',
              lineWidth: 1
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`,
              afterBody: items => {
                if (!items.length) return '';
                const idx = items[0].dataIndex;
                const row = q[idx] || { expected: 0, paid: 0 };
                const outstanding = Number(row.expected || 0) - Number(row.paid || 0);
                return [`Expected: ${formatKsh(row.expected)}`, `Paid: ${formatKsh(row.paid)}`, `Outstanding: ${formatKsh(outstanding)}`];
              }
            }
          }
        }
      }
    });
  }
};

const addSorting = () => {
  document.querySelectorAll('table.sortable th').forEach((th, index) => {
    th.addEventListener('click', () => {
      const table = th.closest('table');
      const tbody = table.querySelector('tbody');
      const rows = [...tbody.querySelectorAll('tr')];
      rows.sort((a, b) => a.children[index].innerText.localeCompare(b.children[index].innerText, undefined, { numeric: true }));
      rows.forEach(row => tbody.appendChild(row));
    });
  });
};

const resetFilters = (formId, resetUrl) => {
  const form = document.getElementById(formId);
  if (!form) {
    if (resetUrl) window.location.href = resetUrl;
    return;
  }

  form.querySelectorAll('select').forEach(select => {
    select.selectedIndex = 0;
  });

  form.querySelectorAll('input[type="text"], input[type="search"], input[type="date"], input[type="month"], input[type="number"]').forEach(input => {
    input.value = '';
  });

  form.querySelectorAll('.filter-tag, .active-filter-tag').forEach(tag => tag.remove());

  if (resetUrl) {
    window.location.href = resetUrl;
    return;
  }

  form.submit();
};

const addLoadingStates = () => {
  document.querySelectorAll('form.filter-form').forEach(form => {
    form.addEventListener('submit', () => {
      let spinner = form.querySelector('.loading-indicator');
      if (!spinner) {
        spinner = document.createElement('div');
        spinner.className = 'loading-indicator';
        spinner.textContent = 'Calculating...';
        form.appendChild(spinner);
      }
      spinner.style.display = 'inline-flex';
    });
  });
};

window.renderBudgetDashboard = drawBudget;

const initBudgetFilters = () => {
  const form = document.getElementById('budgetFilterForm');
  if (!form) return;

  const viewSelect = form.querySelector('select[name="view"]');
  const referenceSelect = document.getElementById('budgetReferenceSelect');
  const yearInput = form.querySelector('input[name="year"]');
  if (!viewSelect || !referenceSelect || !yearInput) return;

  const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const formatKsh = value => `KSh ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const showCardsLoadingState = () => {
    document.querySelectorAll('.calculable-card').forEach(node => {
      if (node instanceof HTMLElement) {
        node.dataset.originalText = node.textContent || '';
        node.textContent = 'Loading...';
      }
    });
  };

  const renderReferenceOptions = mode => {
    const priorValue = referenceSelect.value;
    referenceSelect.innerHTML = '';

    if (mode === 'quarterly') {
      for (let quarter = 1; quarter <= 4; quarter += 1) {
        const option = document.createElement('option');
        const selectedYear = Number(yearInput.value || new Date().getFullYear());
        option.value = `${selectedYear}-${String(((quarter - 1) * 3) + 1).padStart(2, '0')}`;
        option.textContent = `Q${quarter}`;
        referenceSelect.appendChild(option);
      }
    } else {
      monthNames.forEach((name, index) => {
        const option = document.createElement('option');
        const selectedYear = Number(yearInput.value || new Date().getFullYear());
        option.value = `${selectedYear}-${String(index + 1).padStart(2, '0')}`;
        option.textContent = name;
        referenceSelect.appendChild(option);
      });
    }

    const match = [...referenceSelect.options].find(option => option.value === priorValue);
    referenceSelect.value = match ? priorValue : referenceSelect.options[0]?.value || '';
  };

  renderReferenceOptions(viewSelect.value);
  const initialSelected = String(window.budgetData?.selectedMonth || '');
  if (initialSelected) {
    const exists = [...referenceSelect.options].some(option => option.value === initialSelected);
    if (exists) referenceSelect.value = initialSelected;
  }

  viewSelect.addEventListener('change', () => {
    renderReferenceOptions(viewSelect.value);
  });

  const syncReferenceToYear = () => {
    renderReferenceOptions(viewSelect.value);
  };

  yearInput.addEventListener('change', syncReferenceToYear);
  yearInput.addEventListener('input', syncReferenceToYear);

  form.addEventListener('submit', () => {
    renderReferenceOptions(viewSelect.value);
    showCardsLoadingState();
  });

  const updateAnalysisCards = () => {
    if (!window.budgetData) return;
    const selectedYear = Number(window.budgetData.selectedYear || yearInput.value || new Date().getFullYear());
    const selectedMonth = String(window.budgetData.selectedMonth || `${selectedYear}-01`);
    const selectedView = String(window.budgetData.selectedView || viewSelect.value || 'monthly');
    const selectedMonthIndex = Math.min(11, Math.max(0, Number(selectedMonth.split('-')[1] || 1) - 1));
    const selectedMonthName = monthNames[selectedMonthIndex];
    const selectedQuarter = Math.ceil((selectedMonthIndex + 1) / 3);
    const referenceText = selectedView === 'quarterly' ? `Q${selectedQuarter}` : selectedMonthName;
    const headline = document.getElementById('analysisPeriodHeadline');
    if (headline) headline.textContent = `Analysis for ${referenceText} ${selectedYear}`;

    const monthly = Array.isArray(window.budgetData.monthly) ? window.budgetData.monthly : [];
    const quarterMap = { Q1: [0, 1, 2], Q2: [3, 4, 5], Q3: [6, 7, 8], Q4: [9, 10, 11] };
    const selectedRows = selectedView === 'quarterly'
      ? (quarterMap[`Q${selectedQuarter}`] || []).map(i => monthly[i]).filter(Boolean)
      : monthly.filter(row => String(row.month_key || '') === selectedMonth);
    const expected = selectedRows.reduce((sum, row) => sum + Number(row.expected || 0), 0);
    const paid = selectedRows.reduce((sum, row) => sum + Number(row.paid || 0), 0);
    const variance = paid - expected;
    const variancePercent = expected > 0 ? (variance / expected) * 100 : 0;

    const budgetLabel = document.getElementById('analysisBudgetLabel');
    const collectedLabel = document.getElementById('analysisCollectedLabel');
    const varianceLabel = document.getElementById('analysisVarianceLabel');
    if (budgetLabel) budgetLabel.textContent = selectedView === 'quarterly' ? 'Quarter Budget:' : 'Month Budget:';
    if (collectedLabel) collectedLabel.textContent = selectedView === 'quarterly' ? 'Quarter Collected:' : 'Month Collected:';
    if (varianceLabel) varianceLabel.textContent = selectedView === 'quarterly' ? 'Quarter Variance:' : 'Month Variance:';
    const budgetValue = document.getElementById('analysisBudgetValue');
    const collectedValue = document.getElementById('analysisCollectedValue');
    const varianceValue = document.getElementById('analysisVarianceValue');
    if (budgetValue) budgetValue.textContent = formatKsh(expected);
    if (collectedValue) collectedValue.textContent = formatKsh(paid);
    if (varianceValue) {
      varianceValue.textContent = formatKsh(variance);
      varianceValue.classList.toggle('negative-financial', variance < 0);
    }
    const varianceBadge = document.getElementById('analysisVarianceBadge');
    if (varianceBadge) {
      varianceBadge.textContent = `${variancePercent.toFixed(2)}%`;
      varianceBadge.classList.remove('paid', 'unpaid');
      varianceBadge.classList.add(variance >= 0 ? 'paid' : 'unpaid');
    }
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    renderReferenceOptions(viewSelect.value);
    showCardsLoadingState();
    const params = new URLSearchParams(new FormData(form));
    params.set('ajax', '1');
    const response = await fetch(`${window.location.pathname}?${params.toString()}`);
    const data = await response.json();
    window.budgetData = { ...window.budgetData, ...data, selectedView: viewSelect.value, selectedMonth: referenceSelect.value };
    const totals = window.budgetData.totals || {};
    const totalBudgetCard = document.getElementById('totalYtdBudgetCard');
    const totalCollectionCard = document.getElementById('totalYtdCollectionCard');
    const totalArrearsCard = document.getElementById('totalYtdArrearsCard');
    if (totalBudgetCard) totalBudgetCard.textContent = formatKsh(totals.totalYtdBudget || 0);
    if (totalCollectionCard) totalCollectionCard.textContent = formatKsh(totals.totalYtdCollection || 0);
    if (totalArrearsCard) totalArrearsCard.textContent = formatKsh(totals.totalYtdArrears || 0);
    updateAnalysisCards();
    drawBudget();
  });
};

document.addEventListener('DOMContentLoaded', () => {
  drawDashboard();
  drawBudget();
  addSorting();
  addLoadingStates();
  initBudgetFilters();
  initDashboardFilters();
  window.resetFilters = resetFilters;
});
