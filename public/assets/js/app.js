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
  const distribution = Array.isArray(window.dashboardData.distribution) ? window.dashboardData.distribution : [];
  const formatKsh = value => `KSh ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  const labels = trend.map(row => row.month_key);
  const expected = trend.map(row => row.expected);
  const paid = trend.map(row => row.paid);
  
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
            ticks: {
              callback: value => formatKsh(value)
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`
            }
          }
        }
      }
    });
  }

  const sparklineCanvas = document.getElementById('collectionSparkline');
  if (sparklineCanvas) {
    new Chart(sparklineCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{ data: paid, borderColor: '#198754', tension: 0.35, pointRadius: 0, fill: false }]
      },
      options: {
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: { x: { display: false }, y: { display: false } },
        elements: { line: { borderWidth: 2 } }
      }
    });
  }

  const expectedVsPaidCanvas = document.getElementById('expectedVsPaid');
  if (expectedVsPaidCanvas) {
    new Chart(expectedVsPaidCanvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Expected (KSH)', data: expected, backgroundColor: '#93c5fd' },
          { label: 'Paid (KSH)', data: paid, backgroundColor: '#198754' }
        ]
      },
      options: {
        scales: {
          y: {
            ticks: {
              callback: value => formatKsh(value)
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`
            }
          }
        }
      }
    });
  }

  const statusPieCanvas = document.getElementById('statusPie');
  if (statusPieCanvas) {
    new Chart(statusPieCanvas, {
      type: 'pie',
      data: {
        labels: distribution.map(row => row.status),
        datasets: [{ data: distribution.map(row => Number(row.total || 0)), backgroundColor: ['#198754', '#ffc107', '#dc3545'] }]
      }
    });
  }
};

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
    new Chart(monthlyCanvas, {
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
            ticks: {
              callback: value => formatKsh(value)
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`
            }
          }
        }
      }
    });
  }

  const quarterlyCanvas = document.getElementById('quarterlyBudget');
  if (quarterlyCanvas) {
    new Chart(quarterlyCanvas, {
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
            ticks: {
              callback: value => formatKsh(value)
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${formatKsh(context.parsed.y)}`
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

document.addEventListener('DOMContentLoaded', () => {
  drawDashboard();
  drawBudget();
  addSorting();
  addLoadingStates();
  window.resetFilters = resetFilters;
});
