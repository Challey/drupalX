(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.dxAiChat = {
    attach(context) {
      once('dx-ai-chat', '.dx-ai-chat', context).forEach((widget) => {
        const form = widget.querySelector('.dx-ai-chat__form');
        const input = widget.querySelector('.dx-ai-chat__input');
        const submit = widget.querySelector('.dx-ai-chat__submit');
        const messages = widget.querySelector('.dx-ai-chat__messages');
        const endpoint = widget.dataset.endpoint;
        const csrf = (drupalSettings.dxAiChat && drupalSettings.dxAiChat.csrfToken) || '';
        const history = [];

        if (!form || !input || !messages || !endpoint) {
          return;
        }

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const text = input.value.trim();
          if (!text) {
            return;
          }

          appendMessage(messages, 'user', text);
          input.value = '';
          input.disabled = true;
          if (submit) {
            submit.disabled = true;
          }
          const pending = appendMessage(messages, 'assistant', '', true);
          let reply = '';

          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
              },
              credentials: 'same-origin',
              body: JSON.stringify({ message: text, history }),
            });
            if (!response.ok || !response.body) {
              const data = await response.json().catch(() => ({}));
              throw new Error(data.error || Drupal.t('Unable to reach AI service.'));
            }
            await readEventStream(response.body, (event, data) => {
              if (event === 'delta') {
                reply += data.content || '';
                pending.textContent = reply;
                messages.scrollTop = messages.scrollHeight;
              } else if (event === 'error') {
                throw new Error(data.message || Drupal.t('Unable to reach AI service.'));
              }
            });
            pending.classList.remove('dx-ai-chat__message--pending');
            if (!reply) {
              pending.textContent = Drupal.t('No response received.');
            }
            history.push({ role: 'user', content: text }, { role: 'assistant', content: reply });
          } catch (error) {
            pending.classList.remove('dx-ai-chat__message--pending');
            pending.textContent = error.message || Drupal.t('Unable to reach AI service.');
          } finally {
            input.disabled = false;
            if (submit) {
              submit.disabled = false;
            }
            input.focus();
          }
        });
      });
    },
  };

  function appendMessage(container, role, content, pending) {
    const div = document.createElement('div');
    div.className = `dx-ai-chat__message dx-ai-chat__message--${role}` + (pending ? ' dx-ai-chat__message--pending' : '');
    div.textContent = content;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return div;
  }

  async function readEventStream(body, onEvent) {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let event = 'message';
    let data = '';

    while (true) {
      const { value, done } = await reader.read();
      buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
      let newline;
      while ((newline = buffer.indexOf('\n')) !== -1) {
        const line = buffer.slice(0, newline).replace(/\r$/, '');
        buffer = buffer.slice(newline + 1);
        if (line === '') {
          if (data) {
            onEvent(event, JSON.parse(data));
          }
          event = 'message';
          data = '';
        } else if (line.startsWith('event:')) {
          event = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
          data += line.slice(5).trim();
        }
      }
      if (done) {
        break;
      }
    }
  }
})(Drupal, once, drupalSettings);
