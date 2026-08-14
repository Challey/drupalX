(function (Drupal, once, drupalSettings) {
  'use strict';

  const SESSION_KEY = 'dxAiChatSessionId';

  Drupal.behaviors.dxAiChat = {
    attach(context) {
      once('dx-ai-chat', '.dx-ai-chat', context).forEach((widget) => {
        const form = widget.querySelector('.dx-ai-chat__form');
        const input = widget.querySelector('.dx-ai-chat__input');
        const submit = widget.querySelector('.dx-ai-chat__submit');
        const clearBtn = widget.querySelector('.dx-ai-chat__clear');
        const messages = widget.querySelector('.dx-ai-chat__messages');
        const endpoint = widget.dataset.endpoint;
        const streamEndpoint = widget.dataset.streamEndpoint || '';
        const csrf = (drupalSettings.dxAiChat && drupalSettings.dxAiChat.csrfToken) || '';
        const streamPreferred = !!(drupalSettings.dxAiChat && drupalSettings.dxAiChat.stream && streamEndpoint);

        if (!form || !input || !messages || !endpoint) {
          return;
        }

        let sessionId = window.sessionStorage.getItem(SESSION_KEY) || '';

        form.addEventListener('submit', async (event) => {
          event.preventDefault();
          const text = input.value.trim();
          if (!text) {
            return;
          }

          appendMessage(messages, 'user', text);
          input.value = '';
          setBusy(input, submit, true);
          const pending = appendMessage(messages, 'assistant', '', true);

          try {
            if (streamPreferred) {
              sessionId = await streamChat({
                endpoint: streamEndpoint,
                csrf,
                text,
                sessionId,
                pending,
                messages,
              });
            } else {
              sessionId = await jsonChat({
                endpoint,
                csrf,
                text,
                sessionId,
                pending,
                messages,
              });
            }
            if (sessionId) {
              window.sessionStorage.setItem(SESSION_KEY, sessionId);
            }
          } catch (error) {
            pending.classList.remove('dx-ai-chat__message--pending');
            pending.textContent = Drupal.t('Unable to reach AI service.');
          } finally {
            setBusy(input, submit, false);
            input.focus();
          }
        });

        if (clearBtn) {
          clearBtn.addEventListener('click', async (event) => {
            event.preventDefault();
            const sid = window.sessionStorage.getItem(SESSION_KEY) || '';
            try {
              await fetch('/dx/ai/chat/clear', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-Token': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ session_id: sid }),
              });
            } catch (e) {
              // Ignore network errors on clear.
            }
            window.sessionStorage.removeItem(SESSION_KEY);
            sessionId = '';
            messages.querySelectorAll('.dx-ai-chat__message').forEach((el, idx) => {
              if (idx > 0) {
                el.remove();
              }
            });
          });
        }
      });
    },
  };

  function setBusy(input, submit, busy) {
    input.disabled = busy;
    if (submit) {
      submit.disabled = busy;
    }
  }

  async function jsonChat({ endpoint, csrf, text, sessionId, pending, messages }) {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ message: text, session_id: sessionId || undefined }),
    });
    const data = await response.json();
    pending.classList.remove('dx-ai-chat__message--pending');
    if (data.error) {
      pending.textContent = data.error;
    } else {
      pending.textContent = data.reply || '';
    }
    messages.scrollTop = messages.scrollHeight;
    return data.session_id || sessionId;
  }

  async function streamChat({ endpoint, csrf, text, sessionId, pending, messages }) {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ message: text, session_id: sessionId || undefined }),
    });

    if (!response.ok || !response.body) {
      // Fallback to non-stream endpoint when stream fails.
      const fallback = endpoint.replace(/\/stream$/, '');
      return jsonChat({
        endpoint: fallback,
        csrf,
        text,
        sessionId,
        pending,
        messages,
      });
    }

    pending.classList.remove('dx-ai-chat__message--pending');
    pending.textContent = '';
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let nextSession = sessionId;
    let sawDelta = false;

    while (true) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const parts = buffer.split('\n\n');
      buffer = parts.pop() || '';
      for (const part of parts) {
        const event = parseSse(part);
        if (!event) {
          continue;
        }
        if (event.event === 'meta' && event.data.session_id) {
          nextSession = event.data.session_id;
        }
        if (event.event === 'delta' && event.data.text) {
          sawDelta = true;
          pending.textContent += event.data.text;
          messages.scrollTop = messages.scrollHeight;
        }
        if (event.event === 'done') {
          if (!sawDelta && event.data.reply) {
            pending.textContent = event.data.reply;
          }
          if (event.data.session_id) {
            nextSession = event.data.session_id;
          }
        }
        if (event.event === 'error') {
          pending.textContent = event.data.error || Drupal.t('AI error');
        }
      }
    }

    if (!pending.textContent) {
      pending.textContent = Drupal.t('Empty response from AI.');
    }
    return nextSession;
  }

  function parseSse(block) {
    const lines = block.split('\n');
    let event = 'message';
    let data = '';
    for (const line of lines) {
      if (line.startsWith('event:')) {
        event = line.slice(6).trim();
      } else if (line.startsWith('data:')) {
        data += line.slice(5).trim();
      }
    }
    if (!data) {
      return null;
    }
    try {
      return { event, data: JSON.parse(data) };
    } catch (e) {
      return null;
    }
  }

  function appendMessage(container, role, content, pending) {
    const div = document.createElement('div');
    div.className = `dx-ai-chat__message dx-ai-chat__message--${role}` + (pending ? ' dx-ai-chat__message--pending' : '');
    div.textContent = content || (pending ? Drupal.t('思考中…') : '');
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return div;
  }
})(Drupal, once, drupalSettings);
