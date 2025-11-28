/**
 * AI Chat WebSocket Client
 * Enhanced JavaScript module for real-time AI chat functionality
 */

class AiChatClient {
    constructor(config) {
        this.config = {
            websocketUrl: 'ws://localhost:8080',
            apiEndpoint: '/api/ai-chat',
            reconnectInterval: 3000,
            maxReconnectAttempts: 5,
            heartbeatInterval: 30000,
            ...config
        };
        
        this.websocket = null;
        this.reconnectAttempts = 0;
        this.heartbeatTimer = null;
        this.isConnected = false;
        this.messageQueue = [];
        this.eventListeners = {};
        
        this.init();
    }
    
    init() {
        this.connectWebSocket();
        this.setupHeartbeat();
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
            this.emit('reconnectFailed');
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
            case 'heartbeat':
                // Heartbeat response - connection is alive
                break;
            default:
                this.emit('message', data);
        }
    }
    
    send(data) {
        if (this.isConnected && this.websocket.readyState === WebSocket.OPEN) {
            this.websocket.send(JSON.stringify(data));
        } else {
            // Queue message for when connection is restored
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
    
    unsubscribeFromSession(sessionId) {
        this.send({
            type: 'unsubscribe',
            session_id: sessionId
        });
    }
    
    sendMessage(sessionId, message, options = {}) {
        this.send({
            type: 'send_message',
            session_id: sessionId,
            message: message,
            ...options
        });
    }
    
    executeAction(sessionId, actionId, actionType, payload = {}) {
        this.send({
            type: 'execute_action',
            session_id: sessionId,
            action_id: actionId,
            action_type: actionType,
            payload: payload
        });
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
    
    disconnect() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
        }
        
        if (this.websocket) {
            this.websocket.close();
        }
        
        this.isConnected = false;
    }
    
    // HTTP fallback methods
    async sendHttpMessage(sessionId, message, options = {}) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCSRFToken()
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    message: message,
                    ...options
                })
            });
            
            return await response.json();
        } catch (error) {
            console.error('HTTP request failed:', error);
            throw error;
        }
    }
    
    async executeHttpAction(sessionId, actionId, actionType, payload = {}) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.getCSRFToken()
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    action_id: actionId,
                    action_type: actionType,
                    payload: payload
                })
            });
            
            return await response.json();
        } catch (error) {
            console.error('HTTP action request failed:', error);
            throw error;
        }
    }
    
    async getChatHistory(sessionId, limit = 50) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/history/${sessionId}?limit=${limit}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': this.getCSRFToken()
                }
            });
            
            return await response.json();
        } catch (error) {
            console.error('Failed to get chat history:', error);
            throw error;
        }
    }
    
    async clearChatHistory(sessionId) {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/history/${sessionId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': this.getCSRFToken()
                }
            });
            
            return await response.json();
        } catch (error) {
            console.error('Failed to clear chat history:', error);
            throw error;
        }
    }
    
    async getAvailableEngines() {
        try {
            const response = await fetch(`${this.config.apiEndpoint}/engines`, {
                method: 'GET'
            });
            
            return await response.json();
        } catch (error) {
            console.error('Failed to get available engines:', error);
            throw error;
        }
    }
    
    getCSRFToken() {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        return metaTag ? metaTag.getAttribute('content') : '';
    }
    
    getConnectionStatus() {
        return {
            connected: this.isConnected,
            reconnectAttempts: this.reconnectAttempts,
            queuedMessages: this.messageQueue.length
        };
    }
}

// Enhanced Chat UI Manager
class AiChatUI {
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
            maxMessages: 100,
            ...options
        };
        
        this.sessionId = this.options.sessionId || 'ai-chat-' + this.generateId();
        this.messageHistory = [];
        
        // Initialize WebSocket client
        this.client = new AiChatClient({
            websocketUrl: this.options.websocketUrl,
            apiEndpoint: this.options.apiEndpoint
        });
        
        this.setupEventListeners();
        this.loadChatHistory();
    }
    
    setupEventListeners() {
        // WebSocket events
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
            if (data.actions && this.options.actions) {
                this.addInteractiveActions(data.actions);
            }
        });
        
        this.client.on('streamingError', (data) => {
            this.hideTypingIndicator();
            this.showError(data.error_message);
        });
        
        this.client.on('actionTriggered', (data) => {
            this.handleActionResponse(data);
        });
    }
    
    async loadChatHistory() {
        try {
            const response = await this.client.getChatHistory(this.sessionId);
            if (response.success && response.messages.length > 0) {
                this.clearMessages();
                response.messages.forEach(message => {
                    this.addMessage(message.role, message.content, message.timestamp, message.actions);
                });
            }
        } catch (error) {
            console.error('Failed to load chat history:', error);
        }
    }
    
    addMessage(role, content, timestamp = null, actions = []) {
        const messageElement = this.createMessageElement(role, content, timestamp);
        this.getMessagesContainer().appendChild(messageElement);
        
        if (actions && actions.length > 0 && this.options.actions) {
            this.addInteractiveActions(actions, messageElement);
        }
        
        this.messageHistory.push({
            role,
            content,
            timestamp: timestamp || new Date().toISOString(),
            actions
        });
        
        // Limit message history
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
    }
    
    createMessageElement(role, content, timestamp) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${role}`;
        
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'message-avatar';
        avatarDiv.innerHTML = `<div class="avatar-${role}">${role === 'user' ? 'You' : 'AI'}</div>`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        const textDiv = document.createElement('div');
        textDiv.className = 'message-text';
        
        if (this.options.enableMarkdown && role === 'assistant') {
            textDiv.innerHTML = this.parseMarkdown(content);
        } else {
            textDiv.textContent = content;
        }
        
        contentDiv.appendChild(textDiv);
        
        if (this.options.showTimestamps) {
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = timestamp ? new Date(timestamp).toLocaleTimeString() : new Date().toLocaleTimeString();
            contentDiv.appendChild(timeDiv);
        }
        
        messageDiv.appendChild(avatarDiv);
        messageDiv.appendChild(contentDiv);
        
        return messageDiv;
    }
    
    parseMarkdown(text) {
        // Basic markdown parsing
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`(.*?)`/g, '<code>$1</code>')
            .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
            .replace(/\n/g, '<br>');
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
            if (this.client.isConnected) {
                this.client.executeAction(this.sessionId, action.id, action.type, action.data);
            } else {
                const response = await this.client.executeHttpAction(this.sessionId, action.id, action.type, action.data);
                this.handleActionResponse(response);
            }
            
            // Add user message showing the action taken
            this.addMessage('user', `Selected: ${action.label}`);
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
    
    showError(message) {
        this.addMessage('assistant', `❌ Error: ${message}`);
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
    
    destroy() {
        this.client.disconnect();
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AiChatClient, AiChatUI };
} else if (typeof window !== 'undefined') {
    window.AiChatClient = AiChatClient;
    window.AiChatUI = AiChatUI;
}
