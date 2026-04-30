document.addEventListener('DOMContentLoaded', () => {
  const uploadForm = document.getElementById('csv-upload-form');
  if (!uploadForm) return;

  const feedback = document.getElementById('upload-feedback');

  uploadForm.addEventListener('submit', async event => {
    event.preventDefault();

    const submitButton = uploadForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;
    if (feedback) feedback.textContent = 'Uploading...';

    try {
      const response = await fetch(uploadForm.action, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new FormData(uploadForm)
      });

      const payload = JSON.parse(await response.text());

      if (feedback) {
        feedback.textContent = payload.message || 'Upload complete.';
        feedback.className = payload.status === 'success' ? 'alert success' : 'alert danger';
      }

      if (payload.status === 'success' && typeof payload.redirect === 'string') {
        window.location.assign(payload.redirect);
      }
    } catch (error) {
      if (feedback) {
        feedback.textContent = 'Upload failed. Please try again.';
        feedback.className = 'alert danger';
      }
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });
});
