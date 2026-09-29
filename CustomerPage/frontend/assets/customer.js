document.querySelectorAll('form[data-async]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const message = form.querySelector('[data-message]');
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'Please wait...';
    message.hidden = true;
    message.className = 'message';

    try {
      const response = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const result = await response.json();
      message.textContent = result.message || (result.success ? 'Saved.' : 'Please try again.');
      if (!result.success) message.classList.add('error');
      message.hidden = false;
      if (result.success && result.redirect) window.location.href = result.redirect;
      else if (result.success && form.dataset.resetOnSuccess === 'true') form.reset();
    } catch {
      message.textContent = 'The server could not be reached. Try again shortly.';
      message.classList.add('error');
      message.hidden = false;
    } finally {
      button.disabled = false;
      button.textContent = label;
    }
  });
});

const toggle = document.querySelector('.mobile-toggle');
const navigation = document.querySelector('.nav');
if (toggle && navigation) toggle.addEventListener('click', () => navigation.classList.toggle('open'));

document.querySelectorAll('[data-logout]').forEach((button) => {
  button.addEventListener('click', async () => {
    const form = new FormData();
    form.set('action', 'logout');
    form.set('csrf_token', button.dataset.csrf);
    const response = await fetch(button.dataset.logout, { method: 'POST', body: form, headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (result.redirect) window.location.href = result.redirect;
  });
});

document.querySelectorAll('.dropdown > .avatar').forEach((button) => {
  button.addEventListener('click', () => button.parentElement.classList.toggle('open'));
});
