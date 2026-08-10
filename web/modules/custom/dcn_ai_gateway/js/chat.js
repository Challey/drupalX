(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dcnAiChat = {
    attach(context) {
      once('dcn-ai-chat', '.dcn-ai-chat', context).forEach((widget) => {
        const form = widget.querySelector('.dcn-ai-chat__form');
        const input = widget.querySelector('.dcn-ai-chat__input');
        const messages = widget.querySelector('.dcn-ai-chat__messages');
        const endpoint = widget.dataset.endpoint;

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

          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({ message: text }),
            });
            const data = await response.json();
            if (data.error) {
              appendMessage(messages, 'assistant', data.error);
            } else {
              appendMessage(messages, 'assistant', data.reply);
            }
          } catch (error) {
            appendMessage(messages, 'assistant', Drupal.t('Unable to reach AI service.'));
          } finally {
            input.disabled = false;
            input.focus();
          }
        });
      });
    },
  };

  function appendMessage(container, role, content) {
    const div = document.createElement('div');
    div.className = `dcn-ai-chat__message dcn-ai-chat__message--${role}`;
    div.textContent = content;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
  }
})(Drupal, once);
