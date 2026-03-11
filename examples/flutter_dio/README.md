# Flutter Dio Client Example

This folder contains a minimal Flutter-friendly client for Laravel AI Engine.

What it includes:

- `Dio` transport
- request and response DTOs
- typed methods for chat, collectors, conversations, and direct text generation

Suggested usage:

1. Copy the `lib/` folder into your Flutter app.
2. Register a `Dio` instance with your base URL and auth interceptor.
3. Inject `AiEngineClient` into your repository, Cubit, BLoC, or Riverpod provider.

Main export:

- `lib/laravel_ai_engine_client.dart`
