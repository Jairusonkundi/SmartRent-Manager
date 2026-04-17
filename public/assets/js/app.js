const drawDashboard = () => {
  if (!window.dashboardData || typeof Chart === 'undefined') return;
  const trend = window.dashboardData.trend || [];
  const distribution = window.dashboardData.distribution || [];
  const formatKsh = value => `KSH ${Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}`;

  const labels = trend.map(row => row.month_key);
  const expected = trend.map(row => Number(row.expected || 0));
  const paid = trend.map(row => Number(row.paid || 0));

  new Chart(document.getElementById('incomeTrend'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'Paid Income (KSH)', data: paid, borderColor: '#198754', tension: 0.3 }] },
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

  new Chart(document.getElementById('expectedVsPaid'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Expected (KSH)', data: expected, backgroundColor: '#93c5fd' }, { label: 'Paid (KSH)', data: paid, backgroundColor: '#198754' }] },
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

  new Chart(document.getElementById('statusPie'), {
    type: 'pie',
    data: {
      labels: distribution.map(row => row.status),
      datasets: [{ data: distribution.map(row => Number(row.total)), backgroundColor: ['#198754', '#ffc107', '#dc3545'] }]
    }
  });
};

const drawBudget = () => {
  if (!window.budgetData || typeof Chart === 'undefined') return;
  const m = window.budgetData.monthly || [];
  const q = window.budgetData.quarterly || [];
  const formatKsh = value => `KSH ${Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}`;

  new Chart(document.getElementById('monthlyBudget'), {
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

  new Chart(document.getElementById('quarterlyBudget'), {
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
