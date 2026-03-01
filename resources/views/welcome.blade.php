<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PWA Todo List</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#6777ef">
    <link rel="apple-touch-icon" href="{{ asset('logo.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .todo-item.completed span {
            text-decoration: line-through;
            color: #9ca3af;
        }
    </style>
</head>

<body class="bg-gray-100 font-sans antialiased">
    <div class="max-w-md mx-auto mt-10 p-6 bg-white rounded-lg shadow-xl">
        <h1 class="text-2xl font-bold mb-4 text-gray-800">PWA Todo List</h1>

        <div class="flex mb-4">
            <input type="text" id="todo-input"
                class="flex-1 border rounded-l px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="What needs to be done?">
            <button id="add-todo"
                class="bg-blue-500 text-white px-4 py-2 rounded-r hover:bg-blue-600 transition-colors">Add</button>
        </div>

        <ul id="todo-list" class="space-y-2">
            <!-- Todos will appear here -->
        </ul>

        <div id="status-indicator" class="mt-4 text-sm text-gray-500 text-center">
            Checking connection...
        </div>

        <button id="pwa-install-btn" style="display: none;"
            class="mt-4 w-full bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 transition-colors">
            Install App
        </button>
    </div>

    <script src="{{ asset('background-sync.js') }}"></script>
    <script src="{{ asset('pwa-install.js') }}"></script>
    <script>
        const todoInput = document.getElementById('todo-input');
        const addTodoBtn = document.getElementById('add-todo');
        const todoList = document.getElementById('todo-list');
        const statusIndicator = document.getElementById('status-indicator');

        // Update status indicator
        function updateOnlineStatus() {
            if (navigator.onLine) {
                statusIndicator.innerText = '';
                statusIndicator.classList.add('hidden');
            } else {
                statusIndicator.innerText = 'Offline - Saved locally';
                statusIndicator.classList.remove('hidden', 'text-green-500');
                statusIndicator.classList.add('text-red-500');
            }
        }
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);

        // Crucial: Check status after a small delay to allow browser to update navigator.onLine
        setTimeout(updateOnlineStatus, 100);
        updateOnlineStatus();

        let localTodos = [];

        // Load Todos
        async function loadTodos() {
            try {
                const response = await fetch('/api/todos');
                const todos = await response.json();

                // Save to localStorage so we can see them while offline
                localStorage.setItem('cached-todos', JSON.stringify(todos));

                renderTodos(todos);
            } catch (err) {
                console.log('Failed to fetch todos, loading from cache...', err);

                // Get items from localStorage if network fails
                const cachedTodos = JSON.parse(localStorage.getItem('cached-todos') || '[]');
                renderTodos(cachedTodos);
            }
        }

        function renderTodos(serverTodos) {
            todoList.innerHTML = '';

            // Combine server todos with local unsynced todos
            const allTodos = [...localTodos, ...serverTodos];

            allTodos.forEach(todo => {
                const li = document.createElement('li');
                li.className =
                    `todo-item flex items-center justify-between p-3 border rounded ${todo.completed ? 'completed bg-gray-50' : 'bg-white'}`;

                const syncIcon = todo.isLocal ? `
                    <span class="inline-flex items-center text-orange-500 animate-pulse" title="Syncing soon...">
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </span>
                ` : '';

                li.innerHTML = `
                    <div class="flex items-center">
                        <input type="checkbox" ${todo.completed ? 'checked' : ''} class="mr-3 h-4 w-4 text-blue-600" ${todo.isLocal ? 'disabled' : ''}>
                        <span class="text-gray-800">${todo.title}</span>
                        ${syncIcon}
                    </div>
                `;
                todoList.appendChild(li);
            });
        }

        async function addTodo() {
            const title = todoInput.value.trim();
            if (!title) return;

            const todoData = {
                title
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            const handleOffline = async () => {
                if (typeof window.queueRequest === 'function') {
                    const fakeRequest = new Request('/api/todos', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify(todoData)
                    });

                    // Add to local UI list immediately
                    const tempId = Date.now();
                    localTodos.unshift({
                        id: tempId,
                        title: title,
                        completed: false,
                        isLocal: true
                    });

                    renderTodos([]); // Will use current localTodos + empty server list

                    await window.queueRequest(fakeRequest);
                    todoInput.value = '';
                } else {
                    console.error('Offline queue handler not found!');
                }
            };

            // If we are definitely offline, don't even try to fetch
            if (!navigator.onLine) {
                await handleOffline();
                return;
            }

            try {
                const response = await fetch('/api/todos', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(todoData)
                });

                if (response.ok) {
                    todoInput.value = '';
                    loadTodos();
                } else {
                    console.error('Server error:', await response.text());
                }
            } catch (err) {
                console.warn('Network request failed, falling back to background sync:', err);
                await handleOffline();
            }
        }

        // Clear local items when online status returns
        window.addEventListener('online', () => {
            updateOnlineStatus();
            // Give the browser a second to complete background sync before refreshing
            setTimeout(() => {
                localTodos = [];
                loadTodos();
            }, 2000);
        });

        addTodoBtn.addEventListener('click', addTodo);
        todoInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') addTodo();
        });

        loadTodos();

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(reg => console.log('[PWA] Service Worker Registered', reg))
                    .catch(err => console.error('[PWA] Service Worker Failed', err));
            });
        }
    </script>
</body>

</html>
