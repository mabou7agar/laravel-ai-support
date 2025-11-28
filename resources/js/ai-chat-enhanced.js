/**
 * Enhanced AI Chat Client
 * Modern JavaScript module for real-time AI chat with advanced features
 * 
 * Features:
 * - WebSocket & HTTP fallback
 * - Markdown rendering with syntax highlighting
 * - Code copy functionality
 * - Voice input support
 * - File upload support
 * - Typing indicators
 * - Message reactions
 * - Search & filter
 * - Export chat history
 * - Dark/Light theme toggle
 * - Keyboard shortcuts
 * - Mobile responsive
 */

class EnhancedAiChatClient {
    constructor(config) {
        this.config = {
            websocketUrl: 'ws://localhost:8080',
            apiEndpoint: '/api/ai-chat',
            reconnectInterval: 3000,
            maxReconnectAttempts: 5,
            heartbeatInterval: 30000,
            enableVoice: true,
            enableFileUpload: true,
            enableRAG: false,
            ragModelClass: null,
            ragMaxContext: 5,
            ragMinScore: 0.5,
            maxFileSize: 10 * 1024 * 1024, // 10MB
            allowedFileTypes: ['image/*', 'application/pdf', '.txt', '.md'],
            useWebSocket: false, // Disable WebSocket by default, use HTTP
            ...config
        };
        
        this.websocket = null;
        this.reconnectAttempts = 0;
        this.heartbeatTimer = null;
        this.isConnected = false;
        this.useHttpFallback = !this.config.useWebSocket; // Use HTTP by default
        this.messageQueue = [];
        this.eventListeners = {};
        this.uploadProgress = {};
        
        this.init();
    }
    
    init() {
        if (this.config.useWebSocket) {
            this.connectWebSocket();
            this.setupHeartbeat();
        } else {
            // HTTP mode - emit connected immediately
            this.isConnected = true;
            this.useHttpFallback = true;
            this.emit('connected');
        }
    }
    
    connectWebSocket() {
        try {
            this.websocket = new WebSocket(this.config.websocketUrl);
            
            this.websocket.onopen = () => {
                this.isConnected = true;
                this.reconnectAttempts = 0;
                this.emit('connected');
                this.processMessageQueue();
            };
            
            this.websocket.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleMessage(data);
                } catch (error) {
                    console.error('Failed to parse WebSocket message:', error);
                }
            };
            
            this.websocket.onclose = () => {
                this.isConnected = false;
                this.emit('disconnected');
                this.attemptReconnect();
            };
            
            this.websocket.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.emit('error', error);
            };
            
        } catch (error) {
            console.error('Failed to connect WebSocket:', error);
            this.emit('error', error);
            this.attemptReconnect();
        }
    }
    
    attemptReconnect() {
        if (this.reconnectAttempts < this.config.maxReconnectAttempts) {
            this.reconnectAttempts++;
            this.emit('reconnecting', this.reconnectAttempts);
            
            setTimeout(() => {
                this.connectWebSocket();
            }, this.config.reconnectInterval);
        } else {
            // Max reconnect attempts reached, switch to HTTP fallback
            console.log('WebSocket reconnection failed, switching to HTTP fallback');
            this.useHttpFallback = true;
            this.isConnected = true;
            this.emit('reconnectFailed');
            this.emit('fallbackToHttp');
        }
    }
    
    setupHeartbeat() {
        this.heartbeatTimer = setInterval(() => {
            if (this.isConnected) {
                this.send({
                    type: 'heartbeat',
                    timestamp: Date.now()
                });
            }
        }, this.config.heartbeatInterval);
    }
    
    handleMessage(data) {
        switch (data.type) {
            case 'ai.response.chunk':
                this.emit('responseChunk', data);
                break;
            case 'ai.response.complete':
                this.emit('responseComplete', data);
                break;
            case 'ai.action.triggered':
                this.emit('actionTriggered', data);
                break;
            case 'ai.streaming.error':
                this.emit('streamingError', data);
                break;
            case 'ai.session.started':
                this.emit('sessionStarted', data);
                break;
            case 'ai.session.ended':
                this.emit('sessionEnded', data);
                break;
            case 'ai.upload.progress':
                this.emit('uploadProgress', data);
                break;
            case 'ai.upload.complete':
                this.emit('uploadComplete', data);
                break;
            case 'ai.rag.context':
                this.emit('ragContext', data);
                break;
            case 'ai.rag.sources':
                this.emit('ragSources', data);
                break;
            case 'heartbeat':
                break;
            default:
                this.emit('message', data);
        }
    }
    
    send(data) {
        // If using HTTP fallback, don't use WebSocket
        if (this.useHttpFallback) {
            // HTTP fallback is handled in sendMessage method
            return;
        }
        
        if (this.isConnected && this.websocket && this.websocket.readyState === WebSocket.OPEN) {
            this.websocket.send(JSON.stringify(data));
        } else {
            this.messageQueue.push(data);
        }
    }
    
    processMessageQueue() {
        while (this.messageQueue.length > 0) {
            const message = this.messageQueue.shift();
            this.send(message);
        }
    }
    
    subscribeToSession(sessionId) {
        this.send({
            type: 'subscribe',
            session_id: sessionId
        });
    }
    
    async sendMessage(sessionId, message, options = {}) {
        const payload = {
            type: 'send_message',
            session_id: sessionId,
            message: message,
            ...options
        };
        
        // Add RAG context if enabled
        if (this.config.enableRAG && this.config.ragModelClass) {
            payload.rag_enabled = true;
            payload.rag_model_class = this.config.ragModelClass;
            payload.rag_max_context = this.config.ragMaxContext;
            payload.rag_min_score = this.config.ragMinScore;
        }
        
        // Use HTTP fallback if WebSocket is not available
        if (this.useHttpFallback) {
            return await this.sendMessageHttp(sessionId, message, options);
        }
        
        this.send(payload);
    }
    
    async sendMessageHttp(sessionId, message, options = {}) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    message: message,
                    engine: options.engine,
                    model: options.model,
                    memory: options.memory !== false,
                    actions: options.actions !== false,
                    streaming: options.streaming !== false
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Emit response events
                this.emit('responseStart', data);
                this.emit('responseChunk', { chunk: data.response });
                this.emit('responseComplete', data);
                return data;
            } else {
                throw new Error(data.error || 'Failed to send message');
            }
        } catch (error) {
            console.error('HTTP send message error:', error);
            this.emit('error', error);
            throw error;
        }
    }
    
    async uploadFile(sessionId, file, onProgress = null) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('session_id', sessionId);
        
        try {
            const xhr = new XMLHttpRequest();
            
            if (onProgress) {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        onProgress(percentComplete);
                    }
                });
            }
            
            return new Promise((resolve, reject) => {
                xhr.onload = () => {
                    if (xhr.status === 200) {
                        resolve(JSON.parse(xhr.responseText));
                    } else {
                        reject(new Error('Upload failed'));
                    }
                };
                
                xhr.onerror = () => reject(new Error('Upload failed'));
                
                xhr.open('POST', `${this.config.apiEndpoint}/upload`);
                xhr.setRequestHeader('X-CSRF-TOKEN', this.getCSRFToken());
                xhr.send(formData);
            });
        } catch (error) {
            console.error('File upload failed:', error);
            throw error;
        }
    }
    
    async exportChatHistory(sessionId, format = 'json') {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/export/${sessionId}?format=${format}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': this.getCSRFToken()
                }
            });
            
            if (format === 'json') {
                return await response.json();
            } else {
                return await response.text();
            }
        } catch (error) {
            console.error('Failed to export chat history:', error);
            throw error;
        }
    }
    
    async searchMessages(sessionId, query) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/search/${sessionId}?q=${encodeURIComponent(query)}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': this.getCSRFToken()
                }
            });
            
            return await response.json();
        } catch (error) {
            console.error('Failed to search messages:', error);
            throw error;
        }
    }
    
    on(event, callback) {
        if (!this.eventListeners[event]) {
            this.eventListeners[event] = [];
        }
        this.eventListeners[event].push(callback);
    }
    
    off(event, callback) {
        if (this.eventListeners[event]) {
            this.eventListeners[event] = this.eventListeners[event].filter(cb => cb !== callback);
        }
    }
    
    emit(event, data = null) {
        if (this.eventListeners[event]) {
            this.eventListeners[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error(`Error in event listener for ${event}:`, error);
                }
            });
        }
    }
    
    getCSRFToken() {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        return metaTag ? metaTag.getAttribute('content') : '';
    }
    
    disconnect() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
        }
        
        if (this.websocket) {
            this.websocket.close();
        }
        
        this.isConnected = false;
    }
}

// Enhanced Chat UI Manager with Modern Features
class EnhancedAiChatUI {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            throw new Error(`Container with ID '${containerId}' not found`);
        }
        
        this.options = {
            streaming: true,
            actions: true,
            memory: true,
            theme: 'light',
            autoScroll: true,
            showTimestamps: true,
            enableMarkdown: true,
            enableCopy: true,
            enableVoice: true,
            enableFileUpload: true,
            enableSearch: true,
            enableExport: true,
            enableReactions: true,
            enableRAG: false,
            ragModelClass: null,
            ragMaxContext: 5,
            ragMinScore: 0.5,
            showRAGSources: true,
            maxMessages: 100,
            syntaxHighlight: true,
            ...options
        };
        
        this.sessionId = this.options.sessionId || 'ai-chat-' + this.generateId();
        this.messageHistory = [];
        this.currentTheme = this.options.theme;
        this.isRecording = false;
        this.mediaRecorder = null;
        
        this.client = new EnhancedAiChatClient({
            websocketUrl: this.options.websocketUrl,
            apiEndpoint: this.options.apiEndpoint
        });
        
        this.setupEventListeners();
        this.setupKeyboardShortcuts();
        this.loadChatHistory();
        this.initializeFeatures();
    }
    
    initializeFeatures() {
        // Initialize syntax highlighting if enabled
        if (this.options.syntaxHighlight && typeof Prism !== 'undefined') {
            this.syntaxHighlighter = Prism;
        }
        
        // Initialize voice recognition if enabled
        if (this.options.enableVoice && 'webkitSpeechRecognition' in window) {
            this.initVoiceRecognition();
        }
        
        // Apply theme
        this.applyTheme(this.currentTheme);
    }
    
    initVoiceRecognition() {
        this.recognition = new webkitSpeechRecognition();
        this.recognition.continuous = false;
        this.recognition.interimResults = true;
        
        this.recognition.onresult = (event) => {
            const transcript = Array.from(event.results)
                .map(result => result[0])
                .map(result => result.transcript)
                .join('');
            
            this.updateInputValue(transcript);
        };
        
        this.recognition.onerror = (event) => {
            console.error('Speech recognition error:', event.error);
            this.stopVoiceRecording();
        };
        
        this.recognition.onend = () => {
            this.stopVoiceRecording();
        };
    }
    
    startVoiceRecording() {
        if (this.recognition) {
            this.isRecording = true;
            this.recognition.start();
            this.emit('voiceRecordingStarted');
            this.updateVoiceButton(true);
        }
    }
    
    stopVoiceRecording() {
        if (this.recognition && this.isRecording) {
            this.isRecording = false;
            this.recognition.stop();
            this.emit('voiceRecordingStopped');
            this.updateVoiceButton(false);
        }
    }
    
    updateVoiceButton(recording) {
        const voiceBtn = this.container.querySelector('.voice-input-btn');
        if (voiceBtn) {
            voiceBtn.classList.toggle('recording', recording);
        }
    }
    
    updateInputValue(value) {
        const input = this.container.querySelector('.message-input');
        if (input) {
            input.value = value;
            this.emit('inputChanged', value);
        }
    }
    
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + Enter to send message
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                const input = this.container.querySelector('.message-input');
                if (input && input.value.trim()) {
                    this.sendMessage(input.value);
                }
            }
            
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.focusSearch();
            }
            
            // Ctrl/Cmd + / to toggle theme
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                this.toggleTheme();
            }
        });
    }
    
    setupEventListeners() {
        this.client.on('connected', () => {
            this.updateConnectionStatus('connected', 'Connected');
            this.client.subscribeToSession(this.sessionId);
        });
        
        this.client.on('disconnected', () => {
            this.updateConnectionStatus('disconnected', 'Disconnected');
        });
        
        this.client.on('reconnecting', (attempt) => {
            this.updateConnectionStatus('connecting', `Reconnecting (${attempt})...`);
        });
        
        this.client.on('responseChunk', (data) => {
            this.appendToLastMessage(data.chunk);
        });
        
        this.client.on('responseComplete', (data) => {
            this.hideTypingIndicator();
            this.finalizeLastMessage();
            if (data.actions && this.options.actions) {
                this.addInteractiveActions(data.actions);
            }
        });
        
        this.client.on('uploadProgress', (data) => {
            this.updateUploadProgress(data.progress);
        });
        
        this.client.on('uploadComplete', (data) => {
            this.handleUploadComplete(data);
        });
        
        this.client.on('ragContext', (data) => {
            this.handleRAGContext(data);
        });
        
        this.client.on('ragSources', (data) => {
            this.handleRAGSources(data);
        });
    }
    
    async loadChatHistory() {
        // Load from localStorage first for instant display
        const cached = this.loadFromLocalStorage();
        if (cached && cached.length > 0) {
            cached.forEach(msg => this.addMessage(msg.role, msg.content, msg.timestamp, msg.actions));
        }
        
        // Then load from server
        try {
            const response = await fetch(`${this.client.config.apiEndpoint}/history/${this.sessionId}`);
            if (response.ok) {
                const data = await response.json();
                if (data.messages && data.messages.length > 0) {
                    this.clearMessages();
                    data.messages.forEach(msg => {
                        this.addMessage(msg.role, msg.content, msg.timestamp, msg.actions);
                    });
                    this.saveToLocalStorage();
                }
            }
        } catch (error) {
            console.error('Failed to load chat history:', error);
        }
    }
    
    addMessage(role, content, timestamp = null, actions = [], sources = []) {
        const messageElement = this.createMessageElement(role, content, timestamp);
        this.getMessagesContainer().appendChild(messageElement);
        
        if (actions && actions.length > 0 && this.options.actions) {
            this.addInteractiveActions(actions, messageElement);
        }
        
        if (sources && sources.length > 0 && this.options.showRAGSources && role === 'assistant') {
            this.addRAGSources(sources, messageElement);
        }
        
        this.messageHistory.push({
            role,
            content,
            timestamp: timestamp || new Date().toISOString(),
            actions,
            sources: sources || []
        });
        
        if (this.messageHistory.length > this.options.maxMessages) {
            this.messageHistory.shift();
            const firstMessage = this.getMessagesContainer().firstChild;
            if (firstMessage) {
                firstMessage.remove();
            }
        }
        
        if (this.options.autoScroll) {
            this.scrollToBottom();
        }
        
        this.saveToLocalStorage();
    }
    
    createMessageElement(role, content, timestamp) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${role}`;
        messageDiv.dataset.timestamp = timestamp || new Date().toISOString();
        
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'message-avatar';
        avatarDiv.innerHTML = `<div class="avatar-${role}">${role === 'user' ? '👤' : '🤖'}</div>`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        const textDiv = document.createElement('div');
        textDiv.className = 'message-text';
        
        if (this.options.enableMarkdown && role === 'assistant') {
            textDiv.innerHTML = this.parseMarkdown(content);
            if (this.options.syntaxHighlight) {
                this.highlightCode(textDiv);
            }
        } else {
            textDiv.textContent = content;
        }
        
        contentDiv.appendChild(textDiv);
        
        // Add message actions (copy, react, etc.)
        const actionsDiv = this.createMessageActions(content);
        contentDiv.appendChild(actionsDiv);
        
        if (this.options.showTimestamps) {
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = timestamp ? this.formatTime(timestamp) : this.formatTime(new Date().toISOString());
            contentDiv.appendChild(timeDiv);
        }
        
        messageDiv.appendChild(avatarDiv);
        messageDiv.appendChild(contentDiv);
        
        return messageDiv;
    }
    
    createMessageActions(content) {
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'message-actions-bar';
        
        // Copy button
        if (this.options.enableCopy) {
            const copyBtn = document.createElement('button');
            copyBtn.className = 'message-action-btn';
            copyBtn.innerHTML = '📋';
            copyBtn.title = 'Copy';
            copyBtn.onclick = () => this.copyToClipboard(content);
            actionsDiv.appendChild(copyBtn);
        }
        
        // Reaction buttons
        if (this.options.enableReactions) {
            const reactions = ['👍', '👎', '❤️', '😊'];
            reactions.forEach(emoji => {
                const reactionBtn = document.createElement('button');
                reactionBtn.className = 'message-action-btn reaction-btn';
                reactionBtn.innerHTML = emoji;
                reactionBtn.onclick = () => this.addReaction(emoji);
                actionsDiv.appendChild(reactionBtn);
            });
        }
        
        return actionsDiv;
    }
    
    parseMarkdown(text) {
        // Enhanced markdown parsing with code blocks
        let html = text
            .replace(/```(\w+)?\n([\s\S]*?)```/g, (match, lang, code) => {
                const language = lang || 'plaintext';
                return `<pre><code class="language-${language}">${this.escapeHtml(code.trim())}</code></pre>`;
            })
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/~~(.+?)~~/g, '<del>$1</del>')
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>')
            .replace(/^#{1,6}\s+(.+)$/gm, (match, text) => {
                const level = match.match(/^#+/)[0].length;
                return `<h${level}>${text}</h${level}>`;
            })
            .replace(/\n/g, '<br>');
        
        return html;
    }
    
    highlightCode(element) {
        if (this.syntaxHighlighter) {
            element.querySelectorAll('pre code').forEach((block) => {
                this.syntaxHighlighter.highlightElement(block);
                this.addCopyButtonToCodeBlock(block.parentElement);
            });
        }
    }
    
    addCopyButtonToCodeBlock(preElement) {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'code-copy-btn';
        copyBtn.innerHTML = '📋 Copy';
        copyBtn.onclick = () => {
            const code = preElement.querySelector('code').textContent;
            this.copyToClipboard(code);
            copyBtn.innerHTML = '✅ Copied!';
            setTimeout(() => copyBtn.innerHTML = '📋 Copy', 2000);
        };
        preElement.style.position = 'relative';
        preElement.appendChild(copyBtn);
    }
    
    copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showNotification('Copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy:', err);
        });
    }
    
    addReaction(emoji) {
        this.showNotification(`Reacted with ${emoji}`);
    }
    
    showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'chat-notification';
        notification.textContent = message;
        this.container.appendChild(notification);
        
        setTimeout(() => notification.classList.add('show'), 10);
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 2000);
    }
    
    toggleTheme() {
        this.currentTheme = this.currentTheme === 'light' ? 'dark' : 'light';
        this.applyTheme(this.currentTheme);
        localStorage.setItem(`chat-theme-${this.sessionId}`, this.currentTheme);
    }
    
    applyTheme(theme) {
        this.container.classList.remove('light', 'dark');
        this.container.classList.add(theme);
    }
    
    async exportChat(format = 'json') {
        try {
            const data = await this.client.exportChatHistory(this.sessionId, format);
            const blob = new Blob([typeof data === 'string' ? data : JSON.stringify(data, null, 2)], {
                type: format === 'json' ? 'application/json' : 'text/plain'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `chat-${this.sessionId}.${format}`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            console.error('Failed to export chat:', error);
        }
    }
    
    focusSearch() {
        const searchInput = this.container.querySelector('.search-input');
        if (searchInput) {
            searchInput.focus();
        }
    }
    
    saveToLocalStorage() {
        try {
            localStorage.setItem(`chat-history-${this.sessionId}`, JSON.stringify(this.messageHistory));
        } catch (error) {
            console.error('Failed to save to localStorage:', error);
        }
    }
    
    loadFromLocalStorage() {
        try {
            const data = localStorage.getItem(`chat-history-${this.sessionId}`);
            return data ? JSON.parse(data) : [];
        } catch (error) {
            console.error('Failed to load from localStorage:', error);
            return [];
        }
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    appendToLastMessage(chunk) {
        const lastMessage = this.getMessagesContainer().querySelector('.message.assistant:last-child .message-text');
        if (lastMessage) {
            lastMessage.textContent += chunk;
            if (this.options.autoScroll) {
                this.scrollToBottom();
            }
        } else {
            this.addMessage('assistant', chunk);
        }
    }
    
    finalizeLastMessage() {
        const lastMessage = this.getMessagesContainer().querySelector('.message.assistant:last-child .message-text');
        if (lastMessage && this.options.enableMarkdown) {
            const content = lastMessage.textContent;
            lastMessage.innerHTML = this.parseMarkdown(content);
            if (this.options.syntaxHighlight) {
                this.highlightCode(lastMessage);
            }
        }
    }
    
    showTypingIndicator() {
        const indicator = this.container.querySelector('.typing-indicator');
        if (indicator) {
            indicator.style.display = 'block';
            if (this.options.autoScroll) {
                this.scrollToBottom();
            }
        }
    }
    
    hideTypingIndicator() {
        const indicator = this.container.querySelector('.typing-indicator');
        if (indicator) {
            indicator.style.display = 'none';
        }
    }
    
    updateConnectionStatus(status, text) {
        const statusIndicator = this.container.querySelector('.status-indicator');
        if (statusIndicator) {
            const statusDot = statusIndicator.querySelector('.status-dot');
            const statusText = statusIndicator.querySelector('.status-text');
            
            if (statusDot) statusDot.className = `status-dot ${status}`;
            if (statusText) statusText.textContent = text;
        }
    }
    
    clearMessages() {
        const messagesContainer = this.getMessagesContainer();
        messagesContainer.innerHTML = '';
        this.messageHistory = [];
        this.saveToLocalStorage();
    }
    
    scrollToBottom() {
        const messagesContainer = this.getMessagesContainer();
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    getMessagesContainer() {
        return this.container.querySelector('.ai-chat-messages');
    }
    
    generateId() {
        return Math.random().toString(36).substr(2, 9);
    }
    
    addRAGSources(sources, messageElement = null) {
        const targetElement = messageElement || this.getMessagesContainer().querySelector('.message.assistant:last-child .message-content');
        if (!targetElement) return;
        
        const sourcesContainer = document.createElement('div');
        sourcesContainer.className = 'rag-sources-container';
        
        const sourcesHeader = document.createElement('div');
        sourcesHeader.className = 'rag-sources-header';
        sourcesHeader.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            <span>Sources (${sources.length})</span>
            <button class="toggle-sources-btn">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        `;
        
        const sourcesList = document.createElement('div');
        sourcesList.className = 'rag-sources-list';
        sourcesList.style.display = 'none';
        
        sources.forEach((source, index) => {
            const sourceItem = document.createElement('div');
            sourceItem.className = 'rag-source-item';
            
            const scoreBar = document.createElement('div');
            scoreBar.className = 'source-score-bar';
            const scorePercent = Math.round((source.score || 0) * 100);
            scoreBar.innerHTML = `
                <div class="score-fill" style="width: ${scorePercent}%"></div>
            `;
            
            const sourceContent = document.createElement('div');
            sourceContent.className = 'source-content';
            sourceContent.innerHTML = `
                <div class="source-header">
                    <span class="source-number">#${index + 1}</span>
                    <span class="source-title">${this.escapeHtml(source.metadata?.title || source.type || 'Document')}</span>
                    <span class="source-score">${scorePercent}%</span>
                </div>
                <div class="source-preview">${this.escapeHtml(this.truncateText(source.content, 150))}</div>
                ${source.metadata ? `<div class="source-metadata">${this.formatSourceMetadata(source.metadata)}</div>` : ''}
            `;
            
            sourceItem.appendChild(scoreBar);
            sourceItem.appendChild(sourceContent);
            sourcesList.appendChild(sourceItem);
        });
        
        sourcesContainer.appendChild(sourcesHeader);
        sourcesContainer.appendChild(sourcesList);
        targetElement.appendChild(sourcesContainer);
        
        // Toggle sources visibility
        const toggleBtn = sourcesHeader.querySelector('.toggle-sources-btn');
        toggleBtn.addEventListener('click', () => {
            const isVisible = sourcesList.style.display !== 'none';
            sourcesList.style.display = isVisible ? 'none' : 'block';
            toggleBtn.classList.toggle('expanded', !isVisible);
        });
    }
    
    handleRAGContext(data) {
        if (data.sources && data.sources.length > 0) {
            this.emit('ragContextReceived', data);
        }
    }
    
    handleRAGSources(data) {
        if (data.sources && data.sources.length > 0) {
            const lastMessage = this.getMessagesContainer().querySelector('.message.assistant:last-child');
            if (lastMessage) {
                this.addRAGSources(data.sources, lastMessage.querySelector('.message-content'));
            }
        }
    }
    
    addInteractiveActions(actions, messageElement = null) {
        const targetElement = messageElement || this.getMessagesContainer().querySelector('.message.assistant:last-child .message-content');
        if (!targetElement) return;
        
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'message-actions';
        
        actions.forEach(action => {
            const button = document.createElement('button');
            button.className = `action-btn ${action.style || ''}`;
            button.textContent = action.label;
            button.dataset.actionId = action.id;
            button.dataset.actionType = action.type;
            button.dataset.actionData = JSON.stringify(action.data || {});
            
            button.addEventListener('click', () => {
                this.executeAction(action);
            });
            
            actionsDiv.appendChild(button);
        });
        
        targetElement.appendChild(actionsDiv);
    }
    
    async executeAction(action) {
        try {
            // Handle different action types
            if (action.type === 'button') {
                const actionData = action.data || {};
                
                // Handle regenerate action
                if (actionData.action === 'regenerate') {
                    // Get the last user message and resend it
                    const lastUserMessage = this.messageHistory.filter(m => m.role === 'user').pop();
                    if (lastUserMessage) {
                        await this.client.sendMessage(this.sessionId, lastUserMessage.content);
                    }
                    return;
                }
                
                // Handle copy action
                if (actionData.action === 'copy') {
                    if (actionData.content) {
                        navigator.clipboard.writeText(actionData.content).then(() => {
                            // Show a temporary success message
                            const successMsg = document.createElement('div');
                            successMsg.textContent = '✓ Copied to clipboard';
                            successMsg.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10000; animation: slideIn 0.3s ease-out;';
                            document.body.appendChild(successMsg);
                            setTimeout(() => successMsg.remove(), 2000);
                        });
                    }
                    return;
                }
                
                // Handle other actions
                if (actionData.action) {
                    this.addMessage('user', `Selected: ${action.label}`);
                    await this.client.sendMessage(this.sessionId, actionData.action);
                }
            } else if (action.type === 'quick_reply') {
                // Handle quick reply
                const reply = action.data?.reply || action.label;
                await this.client.sendMessage(this.sessionId, reply);
            }
        } catch (error) {
            this.showError('Failed to execute action');
            console.error('Action execution error:', error);
        }
    }
    
    handleActionResponse(data) {
        if (data.success) {
            if (data.message) {
                this.addMessage('assistant', data.message);
            }
        } else {
            this.showError(data.error || 'Action failed');
        }
    }
    
    showError(message) {
        this.addMessage('assistant', `❌ Error: ${message}`);
    }
    
    formatSourceMetadata(metadata) {
        const items = [];
        if (metadata.id) items.push(`ID: ${metadata.id}`);
        if (metadata.type) items.push(`Type: ${metadata.type}`);
        if (metadata.created_at) items.push(`Date: ${new Date(metadata.created_at).toLocaleDateString()}`);
        return items.join(' • ');
    }
    
    truncateText(text, maxLength) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
    
    destroy() {
        this.client.disconnect();
        if (this.recognition) {
            this.recognition.stop();
        }
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { EnhancedAiChatClient, EnhancedAiChatUI };
} else if (typeof window !== 'undefined') {
    window.EnhancedAiChatClient = EnhancedAiChatClient;
    window.EnhancedAiChatUI = EnhancedAiChatUI;
}
