const chartState = {
  incomeTrend: null,
  expectedVsPaid: null,
  statusPie: null
};

const formatKsh = (value) => {
  const amount = Number(value || 0);
  return `KSH ${amount.toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
};

const destroyDashboardCharts = () => {
  Object.keys(chartState).forEach((key) => {
    if (chartState[key]) {
      chartState[key].destroy();
      chartState[key] = null;
    }
  });
};

const renderDashboardSummary = (summary) => {
  const expected = document.getElementById('metricExpected');
  const paid = document.getElementById('metricPaid');
  const outstanding = document.getElementById('metricOutstanding');
  const collectionPercent = document.getElementById('metricCollectionPercent');

  if (!expected || !paid || !outstanding || !collectionPercent) return;

  expected.textContent = formatKsh(summary.expected);
  paid.textContent = formatKsh(summary.paid);
  outstanding.textContent = formatKsh(summary.outstanding);
  collectionPercent.textContent = `${Number(summary.collection_percent || 0).toFixed(2)}%`;
};

const drawDashboard = () => {
  if (!window.dashboardData || typeof Chart === 'undefined') return;
  const trend = window.dashboardData.trend || [];
  const distribution = window.dashboardData.distribution || [];

  renderDashboardSummary(window.dashboardData.summary || {});

  const labels = trend.map((row) => row.month_key);
  const expected = trend.map((row) => Number(row.expected || 0));
  const paid = trend.map((row) => Number(row.paid || 0));

  destroyDashboardCharts();

  chartState.incomeTrend = new Chart(document.getElementById('incomeTrend'), {
    type: 'line',
    data: {
      labels,
      datasets: [{ label: 'Paid Income', data: paid, borderColor: '#198754', tension: 0.3 }]
    }
  });

  chartState.expectedVsPaid = new Chart(document.getElementById('expectedVsPaid'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Expected', data: expected, backgroundColor: '#93c5fd' },
        { label: 'Paid', data: paid, backgroundColor: '#198754' }
      ]
    }
  });

  chartState.statusPie = new Chart(document.getElementById('statusPie'), {
    type: 'pie',
    data: {
      labels: distribution.map((row) => row.status),
      datasets: [{ data: distribution.map((row) => Number(row.total)), backgroundColor: ['#198754', '#f97316'] }]
    }
  });
};

const wireCsvImport = () => {
  const form = document.getElementById('csvUploadForm');
  const feedback = document.getElementById('importFeedback');
  if (!form || !feedback) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    feedback.style.display = 'none';

    try {
      const response = await fetch('/public/import_handler.php', {
        method: 'POST',
        body: formData
      });
      const payload = await response.json();

      feedback.className = `alert ${payload.success ? 'success' : 'error'}`;
      feedback.textContent = payload.message || 'Import complete.';
      feedback.style.display = 'block';

      if (payload.success) {
        window.dashboardData = {
          ...window.dashboardData,
          month: payload.month,
          summary: payload.summary || {},
          trend: payload.trend || [],
          distribution: payload.distribution || []
        };
        drawDashboard();
        form.reset();
      }
    } catch (error) {
      feedback.className = 'alert error';
      feedback.textContent = 'Import failed. Please retry with a valid CSV file.';
      feedback.style.display = 'block';
    }
  });
};

const drawBudget = () => {
  if (!window.budgetData || typeof Chart === 'undefined') return;
  const m = window.budgetData.monthly || [];
  const q = window.budgetData.quarterly || [];

  new Chart(document.getElementById('monthlyBudget'), {
    type: 'bar',
    data: {
      labels: m.map(x => x.month_key),
      datasets: [
        { label: 'Expected', data: m.map(x => x.expected), backgroundColor: '#93c5fd' },
        { label: 'Paid', data: m.map(x => x.paid), backgroundColor: '#198754' },
        { label: 'Outstanding', data: m.map(x => x.outstanding), backgroundColor: '#dc3545' }
      ]
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
  wireCsvImport();
  drawBudget();
  addSorting();
});
