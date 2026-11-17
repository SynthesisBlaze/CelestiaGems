<?php
$year = date('Y');
?>
<style>
    /* ===== Footer component styles ===== */
    .cg-footer {
        border-top: 1px solid var(--cg-hair);
        font-family: var(--font-display);
        background: var(--cg-bg);
    }

    .cg-footer .cg-brand {
        font-weight: 500;
        font-size: 1.6rem;
        color: var(--cg-text);
        display: flex;
        align-items: center;
        gap: .75rem;
        text-decoration: none;
    }

    .cg-tagline {
        font-size: 1.4rem;
        color: var(--cg-text);
        margin-top: 1rem;
        margin-left: 2.8rem;
        line-height: 1.2;
    }

    .cg-footer .em {
        color: var(--cg-gold);
        font-style: italic;
    }

    .cg-footer h4 {
        font-weight: 600;
        font-size: 1.25rem;
        color: var(--cg-text);
    }

    .cg-footer a {
        color: var(--cg-muted);
        font-size: 1.05rem;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .cg-footer a:hover {
        color: var(--cg-gold-light);
    }

    /* ===== AI Shopping Assistant widget ===== */
    .cg-chat-toggle {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--cg-gold);
        color: var(--cg-ink-on-gold);
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 6px 20px rgba(0, 0, 0, calc(0.35 * var(--cg-shadow-strength)));
        cursor: pointer;
        z-index: 1050;
        transition: transform 0.2s ease;
    }

    .cg-chat-toggle:hover {
        transform: scale(1.06);
    }

    .cg-chat-toggle svg {
        width: 24px;
        height: 24px;
    }

    .cg-chat-panel {
        position: fixed;
        bottom: 5.5rem;
        right: 1.5rem;
        width: min(340px, calc(100vw - 2rem));
        height: min(460px, calc(100vh - 8rem));
        background: var(--cg-bg-alt);
        border: 1px solid var(--cg-hair);
        border-radius: 14px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, calc(0.4 * var(--cg-shadow-strength)));
        display: none;
        flex-direction: column;
        overflow: hidden;
        z-index: 1050;
        font-family: var(--font-body);
    }

    .cg-chat-panel.open {
        display: flex;
    }

    .cg-chat-header {
        padding: .9rem 1.1rem;
        background: var(--cg-bg);
        border-bottom: 1px solid var(--cg-hair);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .cg-chat-header strong {
        font-family: var(--font-display);
        font-size: 1.05rem;
        color: var(--cg-text);
    }

    .cg-chat-close {
        background: none;
        border: none;
        color: var(--cg-muted);
        font-size: 1.2rem;
        line-height: 1;
        cursor: pointer;
    }

    .cg-chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: .6rem;
    }

    .cg-chat-msg {
        max-width: 85%;
        padding: .55rem .8rem;
        border-radius: 10px;
        font-size: .85rem;
        line-height: 1.4;
    }

    .cg-chat-msg.user {
        align-self: flex-end;
        background: var(--cg-gold);
        color: var(--cg-ink-on-gold);
    }

    .cg-chat-msg.bot {
        align-self: flex-start;
        background: var(--cg-divider);
        color: var(--cg-text);
    }

    .cg-chat-msg.typing {
        font-style: italic;
        color: var(--cg-muted);
        background: transparent;
        padding: 0 .2rem;
    }

    .cg-chat-input-row {
        display: flex;
        gap: .5rem;
        padding: .8rem;
        border-top: 1px solid var(--cg-hair);
    }

    .cg-chat-input-row input {
        flex: 1;
        background: var(--cg-surface);
        border: 1px solid var(--cg-hair);
        color: var(--cg-text);
        border-radius: 20px;
        padding: .5rem 1rem;
        font-size: .85rem;
        outline: none;
    }

    .cg-chat-input-row input::placeholder {
        color: var(--cg-muted);
    }

    .cg-chat-input-row button {
        background: var(--cg-gold);
        color: var(--cg-ink-on-gold);
        border: none;
        border-radius: 20px;
        padding: .5rem 1rem;
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
    }

    .cg-chat-input-row button:disabled {
        opacity: .6;
        cursor: not-allowed;
    }
</style>

<footer class="cg-footer pt-5 pb-4 mt-5">
    <div class="container">
        <div class="row gy-4 pb-4">
            <div class="col-lg-4 col-md-6">
                <a class="cg-brand" href="/">
                    <img src="/img/logo.png" alt="Celestia Gems Logo" width="34" height="34" style="object-fit: contain;">
                    Celestia Gems
                </a>
                <p class="cg-tagline">Jewelry drawn<br>from the <span class="em">stars.</span></p>
            </div>

            <div class="col-lg-2 col-6">
                <h4 class="mb-4">Shop</h4>
                <ul class="list-unstyled">
                    <li class="mb-3"><a href="/pages/shop.php">All Jewelry</a></li>
                    <li class="mb-3"><a href="/pages/shop.php?search=ring">Rings</a></li>
                    <li class="mb-3"><a href="/pages/shop.php?search=necklace">Necklaces</a></li>
                    <li class="mb-3"><a href="/pages/shop.php?search=earring">Earrings</a></li>
                    <li class="mb-3"><a href="/pages/shop.php?search=gemstone">Gemstones</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-6">
                <h4 class="mb-4">Services</h4>
                <ul class="list-unstyled">
                    <li class="mb-3"><a href="/pages/service.php?cat=11">Custom Orders</a></li>
                    <li class="mb-3"><a href="/pages/service.php?cat=12&amp;q=repair">Repair</a></li>
                    <li class="mb-3"><a href="/pages/service.php?cat=12&amp;q=clean">Polishing and Cleaning</a></li>
                    <li class="mb-3"><a href="/pages/service.php?cat=12&amp;q=apprais|valu">Appraisal and Valuation</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-6">
                <h4 class="mb-4">Support</h4>
                <ul class="list-unstyled">
                    <li class="mb-3"><a href="/pages/faq.php">FAQ</a></li>
                    <li class="mb-3"><a href="/pages/contactus.php">Contact Us</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-6">
                <h4 class="mb-4">Company</h4>
                <ul class="list-unstyled">
                    <li class="mb-3"><a href="/pages/aboutus.php">About Us</a></li>
                </ul>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2 border-top pt-3 small" style="border-color: var(--cg-hair) !important; color: var(--cg-muted);">
            <span>@<?= $year ?> Celestia Gems. All rights reserved.</span>
            <span class="d-flex gap-4"><a href="/pages/privacy.php">Privacy Policy</a> <a href="/pages/terms.php">Terms of Service</a></span>
        </div>
    </div>
</footer>

<!-- ===== AI Shopping Assistant widget ===== -->
<button type="button" class="cg-chat-toggle" id="cgChatToggle" aria-label="Chat with us">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M21 11.5a8.5 8.5 0 01-8.5 8.5c-1.35 0-2.62-.32-3.75-.9L3 21l1.9-5.75A8.5 8.5 0 1121 11.5z" />
    </svg>
</button>

<div class="cg-chat-panel" id="cgChatPanel">
    <div class="cg-chat-header">
        <strong>Celestia Gems Assistant</strong>
        <button type="button" class="cg-chat-close" id="cgChatClose" aria-label="Close chat">&times;</button>
    </div>
    <div class="cg-chat-messages" id="cgChatMessages">
        <div class="cg-chat-msg bot">Hi! I can help you find a piece, check sizing, or answer questions about your order. What are you looking for today?</div>
    </div>
    <div class="cg-chat-input-row">
        <input type="text" id="cgChatInput" placeholder="Type a message&hellip;" maxlength="500" autocomplete="off">
        <button type="button" id="cgChatSend">Send</button>
    </div>
</div>

<script>
    (function() {
        const toggle = document.getElementById('cgChatToggle');
        const panel = document.getElementById('cgChatPanel');
        const closeBtn = document.getElementById('cgChatClose');
        const messages = document.getElementById('cgChatMessages');
        const input = document.getElementById('cgChatInput');
        const sendBtn = document.getElementById('cgChatSend');

        // In-memory only (per page load) — no persistence needed for this widget.
        let conversationHistory = [];

        toggle.addEventListener('click', () => panel.classList.toggle('open'));
        closeBtn.addEventListener('click', () => panel.classList.remove('open'));

        function addMessage(text, who) {
            const div = document.createElement('div');
            div.className = 'cg-chat-msg ' + who;
            div.textContent = text;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
            return div;
        }

        function sendMessage() {
            const text = input.value.trim();
            if (!text) return;

            addMessage(text, 'user');
            input.value = '';
            input.disabled = true;
            sendBtn.disabled = true;

            const typingEl = addMessage('Typing…', 'typing');

            const formData = new FormData();
            formData.append('message', text);
            formData.append('history', JSON.stringify(conversationHistory));

            fetch('/functions/ai-chat.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(res => res.json().catch(() => ({
                    success: false
                })))
                .then(data => {
                    typingEl.remove();
                    if (data.success) {
                        addMessage(data.reply, 'bot');
                        conversationHistory.push({ role: 'user', text: text });
                        conversationHistory.push({ role: 'model', text: data.reply });
                    } else {
                        addMessage(data.message || "Sorry, something went wrong. Please try again.", 'bot');
                    }
                })
                .catch(() => {
                    typingEl.remove();
                    addMessage("Sorry, I couldn't reach the assistant. Please try again.", 'bot');
                })
                .finally(() => {
                    input.disabled = false;
                    sendBtn.disabled = false;
                    input.focus();
                });
        }

        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') sendMessage();
        });
    })();
</script>