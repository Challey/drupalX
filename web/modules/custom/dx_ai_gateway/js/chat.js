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

        // Maintain in-session conversation history
        const conversation = [];

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const text = input.value.trim();
          if (!text) {
            return;
          }

          appendMessage(messages, 'user', text);
          conversation.push({ role: 'user', content: text });

          input.value = '';
          input.disabled = true;
          if (submit) {
            submit.disabled = true;
          }

          const assistantMsgElement = appendMessage(messages, 'assistant', '', true);
          let fullReply = '';

          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                messages: conversation,
                stream: true,
              }),
            });

            if (!response.ok) {
              const errJson = await response.json().catch(() => ({}));
              throw new Error(errJson.error || `HTTP error ${response.status}`);
            }

            assistantMsgElement.classList.remove('dx-ai-chat__message--pending');

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

            while (true) {
              const { done, value } = await reader.read();
              if (done) break;

              buffer += decoder.decode(value, { stream: true });
              const lines = buffer.split('\n');
              buffer = lines.pop(); // keep partial line

              for (const line of lines) {
                const trimmed = line.trim();
                if (!trimmed || !trimmed.startsWith('data:')) continue;
                const payloadStr = trimmed.slice(5).trim();
                if (payloadStr === '[DONE]') break;

                try {
                  const data = JSON.parse(payloadStr);
                  if (data.error) {
                    throw new Error(data.error);
                  }
                  if (data.chunk) {
                    fullReply += data.chunk;
                    assistantMsgElement.textContent = fullReply;
                    messages.scrollTop = messages.scrollHeight;
                  }
                } catch (e) {
                  if (e.message && !e.message.includes('JSON')) {
                    throw e;
                  }
                }
              }
            }

            if (!fullReply) {
              fullReply = Drupal.t('无应答内容');
              assistantMsgElement.textContent = fullReply;
            }
            conversation.push({ role: 'assistant', content: fullReply });
          } catch (error) {
            assistantMsgElement.classList.remove('dx-ai-chat__message--pending');
            assistantMsgElement.textContent = error.message || Drupal.t('Unable to reach AI service.');
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
    div.textContent = content || (pending ? Drupal.t('思考中…') : '');
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return div;
  }
})(Drupal, once, drupalSettings);

