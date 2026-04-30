const drawDashboard = () => {
  if (!window.dashboardData || typeof Chart === 'undefined') return;

  const trend = Array.isArray(window.dashboardData.trend)
    ? window.dashboardData.trend.map(row => ({
        month_key: String(row.month_key || ''),
        expected: Number(row.expected || 0),
        paid: Number(row.paid || 0)
      }))
    : [];
  const distribution = Array.isArray(window.dashboardData.distribution) ? window.dashboardData.distribution : [];
  const formatKsh = value => `KSH ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  const labels = trend.map(row => row.month_key);
  const expected = trend.map(row => row.expected);
  const paid = trend.map(row => row.paid);
  const paidLast6 = Array.isArray(window.dashboardData.paidLast6)
    ? window.dashboardData.paidLast6.map(value => Number(value || 0))
    : paid;

  const incomeTrendCanvas = document.getElementById('incomeTrend');
  if (incomeTrendCanvas) {
    new Chart(incomeTrendCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{ label: 'Paid Income (KSH)', data: paidLast6, borderColor: '#198754', tension: 0.3 }]
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
  const q = Array.isArray(window.budgetData.quarterly)
    ? window.budgetData.quarterly.map(x => ({
        quarter_label: String(x.quarter_label || ''),
        expected: Number(x.expected || 0),
        paid: Number(x.paid || 0)
      }))
    : [];
  const formatKsh = value => `KSH ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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

document.addEventListener('DOMContentLoaded', () => {
  drawDashboard();
  drawBudget();
  addSorting();
});
