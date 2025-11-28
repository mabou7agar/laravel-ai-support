<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'AI Chat Assistant' }}</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        
        .message-fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .typing-dot {
            animation: typing 1.4s infinite;
        }
        
        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% { opacity: 0.3; }
            30% { opacity: 1; }
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        
        /* Option Cards */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        
        .option-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }
        
        .option-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            border-color: rgba(255, 255, 255, 0.3);
        }
        
        .option-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            font-weight: 700;
            font-size: 16px;
            color: white;
            margin-bottom: 8px;
        }
        
        .option-text {
            color: white;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.5;
        }
        
        @media (max-width: 768px) {
            .options-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div x-data="chatApp()" x-init="init()" class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="gradient-bg text-white shadow-lg">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold">AI Chat Assistant</h1>
                            <p class="text-sm text-purple-100">Powered by Laravel AI Engine</p>
                        </div>
                    </div>
                    
                    <!-- Memory Stats -->
                    <div class="glass-effect rounded-lg px-4 py-2 text-sm">
                        <div class="flex items-center space-x-4">
                            <div>
                                <span class="text-purple-200">Messages:</span>
                                <span class="font-bold" x-text="memoryStats.total_messages"></span>
                            </div>
                            <div>
                                <span class="text-purple-200">Tokens:</span>
                                <span class="font-bold" x-text="memoryStats.estimated_tokens"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 h-full">
                
                <!-- Sidebar - Available Actions -->
                <div class="lg:col-span-1 space-y-4">
                    <!-- Settings Panel -->
                    <div class="bg-white rounded-lg shadow-md p-4">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            Settings
                        </h3>
                        
                        <div class="space-y-3">
                            <!-- Memory Toggle -->
                            <label class="flex items-center justify-between cursor-pointer">
                                <span class="text-sm font-medium text-gray-700">Memory</span>
                                <div class="relative">
                                    <input type="checkbox" x-model="memoryEnabled" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                                </div>
                            </label>

                            <!-- Intelligent RAG Status (Always On) -->
                            <div class="flex items-center justify-between p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <div>
                                        <span class="text-sm font-medium text-purple-900">Intelligent RAG</span>
                                        <p class="text-xs text-purple-700">AI decides when to search</p>
                                    </div>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Active
                                </span>
                            </div>

                            <div class="text-xs text-gray-600 p-3 bg-gray-50 rounded-lg">
                                <strong>🤖 Smart RAG:</strong> The AI automatically searches your knowledge base when needed. No manual toggle required!
                            </div>
                        </div>
                    </div>

                    <!-- Available Actions -->
                    <div class="bg-white rounded-lg shadow-md p-4">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            Available Actions
                        </h3>
                        
                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            <template x-for="action in availableActions" :key="action.id">
                                <button 
                                    @click="suggestAction(action)"
                                    class="w-full text-left px-3 py-2 rounded-lg hover:bg-purple-50 transition-colors border border-gray-200"
                                >
                                    <div class="font-medium text-sm" x-text="action.label"></div>
                                    <div class="text-xs text-gray-500" x-text="action.description"></div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Context Summary -->
                    <div class="bg-white rounded-lg shadow-md p-4">
                        <h3 class="text-lg font-semibold mb-3">Context</h3>
                        <p class="text-sm text-gray-600" x-text="contextSummary"></p>
                    </div>
                </div>

                <!-- Chat Area -->
                <div class="lg:col-span-3">
                    <div class="bg-white rounded-lg shadow-md flex flex-col h-[calc(100vh-200px)]">
                        
                        <!-- Chat Messages -->
                        <div class="flex-1 overflow-y-auto p-6 space-y-4" x-ref="messagesContainer">
                            <!-- Welcome Message -->
                            <div class="flex items-start space-x-3 message-fade-in">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white text-sm font-bold">
                                    AI
                                </div>
                                <div class="flex-1 bg-gray-100 rounded-lg p-4">
                                    <p class="text-gray-800">👋 Hello! I'm your AI assistant. I can help you with:</p>
                                    <ul class="mt-2 space-y-1 text-sm text-gray-600">
                                        <li>• Creating blog posts, emails, and tasks</li>
                                        <li>• Managing your calendar and schedule</li>
                                        <li>• Analyzing documents and emails</li>
                                        <li>• 📚 <strong>RAG Mode:</strong> Answer questions based on your documents</li>
                                        <li>• And much more!</li>
                                    </ul>
                                    <div class="mt-3 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                        <p class="text-xs text-purple-800">
                                            💡 <strong>Tip:</strong> Enable RAG Mode in settings and paste your documents to get context-aware answers!
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Messages -->
                            <template x-for="(message, index) in messages" :key="index">
                                <div 
                                    class="flex items-start space-x-3 message-fade-in"
                                    :class="message.role === 'user' ? 'flex-row-reverse space-x-reverse' : ''"
                                >
                                    <div 
                                        class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold"
                                        :class="message.role === 'user' ? 'bg-blue-500' : 'bg-gradient-to-br from-purple-500 to-pink-500'"
                                    >
                                        <span x-text="message.role === 'user' ? 'You' : 'AI'"></span>
                                    </div>
                                    <div 
                                        class="flex-1 rounded-lg p-4 max-w-3xl"
                                        :class="message.role === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-800'"
                                    >
                                        <p class="whitespace-pre-wrap" x-html="formatMessage(message.content, message.numbered_options)"></p>
                                        
                                        <!-- Option Cards -->
                                        <template x-if="message.numbered_options && message.numbered_options.length > 0">
                                            <div class="options-grid">
                                                <template x-for="option in message.numbered_options" :key="option.number">
                                                    <div 
                                                        class="option-card"
                                                        @click="selectOption(option.text)"
                                                    >
                                                        <div class="option-number" x-text="option.number"></div>
                                                        <p class="option-text" x-text="stripMarkdown(option.text)"></p>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        
                                        <!-- Actions -->
                                        <template x-if="message.actions && message.actions.length > 0">
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <template x-for="action in message.actions" :key="action.id">
                                                    <button 
                                                        @click="executeAction(action)"
                                                        class="px-3 py-1 text-xs rounded-full border transition-colors"
                                                        :class="message.role === 'user' ? 'border-white text-white hover:bg-white hover:text-blue-500' : 'border-purple-500 text-purple-600 hover:bg-purple-500 hover:text-white'"
                                                        x-text="action.label"
                                                    ></button>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- Typing Indicator -->
                            <div x-show="isTyping" class="flex items-start space-x-3" x-cloak>
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white text-sm font-bold">
                                    AI
                                </div>
                                <div class="bg-gray-100 rounded-lg p-4">
                                    <div class="flex space-x-1">
                                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                                        <div class="w-2 h-2 bg-gray-400 rounded-full typing-dot"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Input Area -->
                        <div class="border-t border-gray-200 p-4">
                            <form @submit.prevent="sendMessage" class="flex items-end space-x-3">
                                <div class="flex-1">
                                    <textarea 
                                        x-model="currentMessage"
                                        @keydown.enter.prevent="if(!$event.shiftKey) sendMessage()"
                                        placeholder="Type your message... (Shift+Enter for new line)"
                                        rows="1"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none"
                                        :disabled="isTyping"
                                    ></textarea>
                                </div>
                                <button 
                                    type="submit"
                                    :disabled="!currentMessage.trim() || isTyping"
                                    class="px-6 py-3 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg font-medium hover:from-purple-600 hover:to-pink-600 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                    </svg>
                                </button>
                            </form>
                            
                            <!-- Quick Actions -->
                            <div class="mt-3 flex items-center justify-between text-sm text-gray-500">
                                <div class="flex space-x-4">
                                    <button @click="clearChat" class="hover:text-purple-600 transition-colors">
                                        Clear Chat
                                    </button>
                                    <button @click="loadHistory" class="hover:text-purple-600 transition-colors">
                                        Load History
                                    </button>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span>Memory:</span>
                                    <span class="font-medium" :class="memoryEnabled ? 'text-green-600' : 'text-gray-400'">
                                        <span x-text="memoryEnabled ? 'ON' : 'OFF'"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function chatApp() {
            return {
                sessionId: 'demo-fixed-session', // Use fixed session for testing
                messages: [],
                currentMessage: '',
                isTyping: false,
                memoryEnabled: true,
                availableActions: [],
                memoryStats: {
                    total_messages: 0,
                    estimated_tokens: 0
                },
                contextSummary: 'No conversation yet',

                async init() {
                    await this.loadAvailableActions();
                    await this.updateMemoryStats();
                },

                async sendMessage() {
                    if (!this.currentMessage.trim()) return;

                    const userMessage = this.currentMessage;
                    this.currentMessage = '';

                    // Add user message
                    this.messages.push({
                        role: 'user',
                        content: userMessage,
                        actions: []
                    });

                    this.scrollToBottom();
                    this.isTyping = true;

                    try {
                        const response = await fetch('/ai-demo/chat/send', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                message: userMessage,
                                session_id: this.sessionId,
                                memory: this.memoryEnabled,
                                actions: true,
                                intelligent_rag: true  // Always enabled
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.messages.push({
                                role: 'assistant',
                                content: data.response,
                                actions: data.actions || [],
                                numbered_options: data.numbered_options || []
                            });

                            await this.updateMemoryStats();
                            await this.updateContextSummary();
                        } else {
                            this.messages.push({
                                role: 'assistant',
                                content: data.error || 'An error occurred',
                                actions: []
                            });
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        this.messages.push({
                            role: 'assistant',
                            content: 'Sorry, I encountered an error. Please try again.',
                            actions: []
                        });
                    } finally {
                        this.isTyping = false;
                        this.scrollToBottom();
                    }
                },

                async loadAvailableActions() {
                    try {
                        const response = await fetch('/ai-demo/chat/actions');
                        const data = await response.json();
                        if (data.success) {
                            this.availableActions = data.actions.slice(0, 10);
                        }
                    } catch (error) {
                        console.error('Error loading actions:', error);
                    }
                },

                async updateMemoryStats() {
                    try {
                        const response = await fetch(`/ai-demo/chat/memory-stats/${this.sessionId}`);
                        const data = await response.json();
                        if (data.success) {
                            this.memoryStats = data.stats;
                        }
                    } catch (error) {
                        console.error('Error loading memory stats:', error);
                    }
                },

                async updateContextSummary() {
                    try {
                        const response = await fetch(`/ai-demo/chat/context-summary/${this.sessionId}`);
                        const data = await response.json();
                        if (data.success) {
                            this.contextSummary = data.summary;
                        }
                    } catch (error) {
                        console.error('Error loading context:', error);
                    }
                },

                async clearChat() {
                    if (!confirm('Are you sure you want to clear the chat history?')) return;

                    try {
                        await fetch('/ai-demo/chat/clear', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                session_id: this.sessionId
                            })
                        });

                        this.messages = [];
                        await this.updateMemoryStats();
                        this.contextSummary = 'No conversation yet';
                    } catch (error) {
                        console.error('Error clearing chat:', error);
                    }
                },

                async loadHistory() {
                    try {
                        const response = await fetch(`/ai-demo/chat/history/${this.sessionId}`);
                        const data = await response.json();
                        if (data.success && data.messages.length > 0) {
                            this.messages = data.messages;
                            this.scrollToBottom();
                        }
                    } catch (error) {
                        console.error('Error loading history:', error);
                    }
                },

                suggestAction(action) {
                    this.currentMessage = `Can you help me ${action.label.toLowerCase()}?`;
                },

                async executeAction(action) {
                    console.log('Executing action:', action);
                    // Implement action execution
                },

                selectOption(value) {
                    this.currentMessage = value;
                    this.sendMessage();
                },
                
                stripMarkdown(text) {
                    // Remove markdown formatting from text
                    return text
                        .replace(/\*\*(.*?)\*\*/g, '$1')  // Remove bold
                        .replace(/\*(.*?)\*/g, '$1')      // Remove italic
                        .replace(/`(.*?)`/g, '$1')        // Remove code
                        .replace(/#{1,6}\s+/g, '')        // Remove headers
                        .replace(/\[(.*?)\]\(.*?\)/g, '$1'); // Remove links
                },

                formatMessage(content, numberedOptions = []) {
                    let formatted = content;
                    
                    // Remove numbered options from content if they exist
                    if (numberedOptions && numberedOptions.length > 0) {
                        numberedOptions.forEach(option => {
                            const pattern = new RegExp(`^${option.number}\\.\\s+.+?(?=\\n\\n|\\n(?=\\d+\\.)|$)`, 'gms');
                            formatted = formatted.replace(pattern, '');
                        });
                        // Clean up extra newlines
                        formatted = formatted.replace(/\n{3,}/g, '\n\n').trim();
                    }
                    
                    // Basic markdown-like formatting
                    return formatted
                        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                        .replace(/\*(.*?)\*/g, '<em>$1</em>')
                        .replace(/`(.*?)`/g, '<code class="bg-gray-200 px-1 rounded">$1</code>');
                },

                scrollToBottom() {
                    this.$nextTick(() => {
                        const container = this.$refs.messagesContainer;
                        container.scrollTop = container.scrollHeight;
                    });
                }
            }
        }
    </script>
</body>
</html>
