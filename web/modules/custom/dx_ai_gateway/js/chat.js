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
        const streamEndpoint = widget.dataset.streamEndpoint;
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

          const requestHistory = history.slice(-20);
          appendMessage(messages, 'user', text);
          history.push({ role: 'user', content: text });
          input.value = '';
          input.disabled = true;
          if (submit) {
            submit.disabled = true;
          }
          const pending = appendMessage(messages, 'assistant', Drupal.t('思考中…'), true);

          try {
            const csrf = await resolveCsrfToken(widget);
            if (!csrf) {
              throw new Error(Drupal.t('Invalid CSRF token.'));
            }
            let reply;
            if (streamEndpoint && window.ReadableStream) {
              reply = await streamReply(streamEndpoint, csrf, text, requestHistory, pending, messages);
            } else {
              reply = await jsonReply(endpoint, csrf, text, requestHistory);
              pending.textContent = reply;
              pending.classList.remove('dx-ai-chat__message--pending');
            }
            history.push({ role: 'assistant', content: reply });
            if (history.length > 20) {
              history.splice(0, history.length - 20);
            }
          } catch (error) {
            history.pop();
            pending.remove();
            appendMessage(messages, 'assistant', error.message || Drupal.t('Unable to reach AI service.'));
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

  async function resolveCsrfToken(widget) {
    const csrfUrl = widget.dataset.csrfUrl
      || (drupalSettings.dxAiChat && drupalSettings.dxAiChat.csrfUrl)
      || Drupal.url('session/token');
    try {
      const response = await fetch(csrfUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
      });
      if (response.ok) {
        const token = (await response.text()).trim();
        if (token) {
          return token;
        }
      }
    } catch (error) {
      // Fall back to the page-embedded token if /session/token is unavailable.
    }
    return (widget.dataset.csrfToken || '').trim()
      || (drupalSettings.dxAiChat && drupalSettings.dxAiChat.csrfToken)
      || '';
  }

  async function jsonReply(endpoint, csrf, message, history) {
    const response = await fetch(endpoint, requestOptions(csrf, message, history));
    const data = await response.json();
    if (!response.ok || data.error) {
      throw new Error(data.error || Drupal.t('Unable to reach AI service.'));
    }
    return data.reply;
  }

  async function streamReply(endpoint, csrf, message, history, target, messages) {
    const response = await fetch(endpoint, requestOptions(csrf, message, history));
    if (!response.ok) {
      let errorMessage = Drupal.t('Unable to reach AI service.');
      try {
        const data = await response.json();
        if (data.error) {
          errorMessage = data.error;
        }
      } catch (error) {
        // Non-JSON error responses fall back to the generic message.
      }
      throw new Error(errorMessage);
    }
    if (!response.body) {
      throw new Error(Drupal.t('Streaming is not supported by this browser.'));
    }

    target.textContent = '';
    target.classList.remove('dx-ai-chat__message--pending');
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let reply = '';

    while (true) {
      const result = await reader.read();
      buffer += decoder.decode(result.value || new Uint8Array(), { stream: !result.done });
      let boundary;
      while ((boundary = buffer.indexOf('\n\n')) !== -1) {
        const block = buffer.slice(0, boundary);
        buffer = buffer.slice(boundary + 2);
        const event = parseEvent(block);
        if (!event) {
          continue;
        }
        if (event.type === 'delta' && event.data.delta) {
          reply += event.data.delta;
          target.textContent = reply;
          messages.scrollTop = messages.scrollHeight;
        } else if (event.type === 'error') {
          throw new Error(event.data.error || Drupal.t('AI stream failed.'));
        }
      }
      if (result.done) {
        break;
      }
    }

    if (!reply) {
      throw new Error(Drupal.t('AI service returned an empty response.'));
    }
    return reply;
  }

  function requestOptions(csrf, message, history) {
    return {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ message, history, csrf_token: csrf }),
    };
  }

  function parseEvent(block) {
    let type = 'message';
    const data = [];
    block.split('\n').forEach((line) => {
      line = line.replace(/\r$/, '');
      if (line.startsWith('event:')) {
        type = line.slice(6).trim();
      } else if (line.startsWith('data:')) {
        data.push(line.slice(5).trim());
      }
    });
    if (!data.length) {
      return null;
    }
    try {
      return { type, data: JSON.parse(data.join('\n')) };
    } catch (error) {
      return null;
    }
  }

  function appendMessage(container, role, content, pending) {
    const div = document.createElement('div');
    div.className = `dx-ai-chat__message dx-ai-chat__message--${role}` + (pending ? ' dx-ai-chat__message--pending' : '');
    div.textContent = content;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return div;
  }
})(Drupal, once, drupalSettings);
