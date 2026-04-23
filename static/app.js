const form = document.getElementById('simForm');
const finalCapital = document.getElementById('finalCapital');
const yearsLeft = document.getElementById('yearsLeft');
const downloadPdfBtn = document.getElementById('downloadPdf');

let chart;
let latestPayload;

function formToPayload() {
  const fd = new FormData(form);
  return {
    current_age: Number(fd.get('current_age')),
    retirement_age: Number(fd.get('retirement_age')),
    monthly_contribution: Number(fd.get('monthly_contribution')),
    annual_return_rate: Number(fd.get('annual_return_rate')),
    current_capital: Number(fd.get('current_capital')),
  };
}

function formatEUR(value) {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value);
}

async function loadProjection(payload) {
  const res = await fetch('/api/projection', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (!res.ok) {
    const detail = await res.json();
    throw new Error(detail.detail || 'Errore nel calcolo proiezione');
  }

  return res.json();
}

function updateChart(points) {
  const labels = points.map((p) => p.age);
  const data = points.map((p) => p.estimated_capital);

  if (chart) {
    chart.destroy();
  }

  chart = new Chart(document.getElementById('capitalChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Capitale stimato',
          data,
          borderColor: '#5a67d8',
          tension: 0.2,
          fill: true,
          backgroundColor: 'rgba(90, 103, 216, 0.15)'
        },
      ],
    },
  });
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  latestPayload = formToPayload();

  try {
    const data = await loadProjection(latestPayload);
    finalCapital.textContent = formatEUR(data.projected_capital);
    yearsLeft.textContent = data.years_to_retirement;
    updateChart(data.points);
  } catch (err) {
    alert(err.message);
  }
});

downloadPdfBtn.addEventListener('click', async () => {
  const payload = latestPayload || formToPayload();

  try {
    const res = await fetch('/api/report', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });

    if (!res.ok) {
      throw new Error('Errore nella generazione PDF');
    }

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'report-previdenziale.pdf';
    a.click();
    URL.revokeObjectURL(url);
  } catch (err) {
    alert(err.message);
  }
});
