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
          const pending = appendMessage(messages, 'assistant', Drupal.t('思考中…'), true);

          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
              },
              credentials: 'same-origin',
              body: JSON.stringify({ message: text }),
            });
            const data = await response.json();
            pending.remove();
            if (data.error) {
              appendMessage(messages, 'assistant', data.error);
            } else {
              appendMessage(messages, 'assistant', data.reply);
            }
          } catch (error) {
            pending.remove();
            appendMessage(messages, 'assistant', Drupal.t('Unable to reach AI service.'));
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
})(Drupal, once, drupalSettings);
