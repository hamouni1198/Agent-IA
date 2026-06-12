/**
 * TripMate frontend
 * Communique avec /chat.php côté serveur PHP.
 * L'historique de conversation vit côté serveur, identifié par un session_id
 * stocké en localStorage côté client.
 */

const API_URL = 'chat.php';


function getSessionId() {
  let id = localStorage.getItem('tripmate_session_id');
  if (!id) {

    id = 'sess-' + crypto.randomUUID().replace(/-/g, '').slice(0, 16);
    localStorage.setItem('tripmate_session_id', id);
  }
  return id;
}

function newSession() {
  localStorage.removeItem('tripmate_session_id');
  return getSessionId();
}


const messagesEl = document.getElementById('messages');
const formEl = document.getElementById('form');
const inputEl = document.getElementById('input');
const sendBtn = document.getElementById('send');
const resetBtn = document.getElementById('reset-btn');


function ajouterMessage(role, contenu, html = false) {
  const wrap = document.createElement('div');
  wrap.className = `message ${role}`;
  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  if (html) {
    bubble.innerHTML = contenu;
  } else {
    bubble.textContent = contenu;
  }
  wrap.appendChild(bubble);
  messagesEl.appendChild(wrap);
  messagesEl.scrollTop = messagesEl.scrollHeight;
  return wrap;
}

function ajouterIndicateurFrappe() {
  const wrap = document.createElement('div');
  wrap.className = 'message assistant';
  wrap.id = 'typing-indicator';
  wrap.innerHTML = `
    <div class="bubble">
      <span class="typing"><span></span><span></span><span></span></span>
    </div>
  `;
  messagesEl.appendChild(wrap);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function retirerIndicateurFrappe() {
  const el = document.getElementById('typing-indicator');
  if (el) el.remove();
}


function escapeHTML(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}


function rendreMarkdownLeger(texte) {
  let html = escapeHTML(texte);
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
  html = html.replace(/(<li>.*<\/li>\n?)+/g, m => `<ul>${m}</ul>`);
  html = html.replace(/\n/g, '<br>');
  return html;
}


async function chargerHistorique() {
  const sessionId = getSessionId();
  try {
    const resp = await fetch(`${API_URL}?session_id=${encodeURIComponent(sessionId)}`);
    if (!resp.ok) return;
    const data = await resp.json();
    if (data.messages && data.messages.length > 0) {

      const welcome = document.querySelector('.welcome');
      if (welcome) welcome.remove();
      data.messages.forEach(m => {
        ajouterMessage(m.role, rendreMarkdownLeger(m.content), true);
      });
    }
  } catch (e) {
    console.error('Erreur chargement historique:', e);
  }
}


async function envoyerMessage(texte) {
  const sessionId = getSessionId();

  ajouterMessage('user', texte);
  ajouterIndicateurFrappe();
  sendBtn.disabled = true;
  inputEl.disabled = true;

  try {
    const resp = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: sessionId, message: texte }),
    });

    if (!resp.ok || !resp.body) {
      retirerIndicateurFrappe();
      const err = await resp.json().catch(() => ({}));
      ajouterMessage('assistant', `⚠️ Erreur : ${err.error || resp.statusText}`);
      return;
    }


    retirerIndicateurFrappe();
    const wrap = ajouterMessage('assistant', '', true);
    const bubble = wrap.querySelector('.bubble');

    const reader = resp.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let texteComplet = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });


      const events = buffer.split('\n\n');
      buffer = events.pop(); // on garde le dernier fragment incomplet

      for (const evt of events) {
        const ligne = evt.split('\n').find(l => l.startsWith('data:'));
        if (!ligne) continue;
        const payload = ligne.slice(5).trim();
        if (!payload) continue;

        let data;
        try { data = JSON.parse(payload); } catch { continue; }

        if (data.delta) {
          texteComplet += data.delta;
          bubble.innerHTML = rendreMarkdownLeger(texteComplet);
          messagesEl.scrollTop = messagesEl.scrollHeight;
        } else if (data.error) {
          texteComplet += `\n⚠️ ${data.error}`;
          bubble.innerHTML = rendreMarkdownLeger(texteComplet);
        }

      }
    }
  } catch (e) {
    retirerIndicateurFrappe();
    ajouterMessage('assistant', `⚠️ Erreur réseau : ${e.message}`);
  } finally {
    sendBtn.disabled = false;
    inputEl.disabled = false;
    inputEl.focus();
  }
}


async function reset() {
  if (!confirm('Démarrer une nouvelle conversation ? L\'historique de cette session sera effacé.')) {
    return;
  }
  const sessionId = getSessionId();
  try {
    await fetch(`${API_URL}?session_id=${encodeURIComponent(sessionId)}`, { method: 'DELETE' });
  } catch (e) {
    console.error('Erreur reset:', e);
  }
  newSession();

  messagesEl.innerHTML = '';
  ajouterMessage('assistant', `Salut ! Je suis <strong>TripMate</strong>, ton assistant de voyage. 🌍<br>Dis-moi où tu veux aller, ton budget, tes envies… et on construit ton voyage ensemble.`, true);
}


formEl.addEventListener('submit', (e) => {
  e.preventDefault();
  const texte = inputEl.value.trim();
  if (!texte) return;
  inputEl.value = '';
  inputEl.style.height = 'auto';
  envoyerMessage(texte);
});


inputEl.addEventListener('input', () => {
  inputEl.style.height = 'auto';
  inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px';
});
inputEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    formEl.requestSubmit();
  }
});

resetBtn.addEventListener('click', reset);


chargerHistorique();
