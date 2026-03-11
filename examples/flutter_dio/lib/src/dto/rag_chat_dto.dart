import 'api_envelope_dto.dart';

class RagChatRequestDto {
  const RagChatRequestDto({
    required this.message,
    required this.sessionId,
    this.engine,
    this.model,
    this.memory = true,
    this.actions = true,
    this.streaming = false,
    this.useIntelligentRag = true,
    this.ragCollections,
    this.searchInstructions,
    this.taskType,
  });

  final String message;
  final String sessionId;
  final String? engine;
  final String? model;
  final bool memory;
  final bool actions;
  final bool streaming;
  final bool useIntelligentRag;
  final List<String>? ragCollections;
  final String? searchInstructions;
  final String? taskType;

  JsonMap toJson() {
    return <String, dynamic>{
      'message': message,
      'session_id': sessionId,
      'engine': engine,
      'model': model,
      'memory': memory,
      'actions': actions,
      'streaming': streaming,
      'use_intelligent_rag': useIntelligentRag,
      'rag_collections': ragCollections,
      'search_instructions': searchInstructions,
      'task_type': taskType,
    }..removeWhere((key, value) => value == null);
  }
}

class RagSourceDto {
  const RagSourceDto({
    required this.raw,
    this.id,
    this.title,
    this.type,
    this.relevance,
  });

  final JsonMap raw;
  final dynamic id;
  final String? title;
  final String? type;
  final double? relevance;

  factory RagSourceDto.fromJson(JsonMap json) {
    return RagSourceDto(
      raw: json,
      id: json['id'],
      title: json['title'] as String?,
      type: json['type'] as String? ?? json['model_type'] as String?,
      relevance: (json['relevance'] as num?)?.toDouble(),
    );
  }
}

class AiActionDto {
  const AiActionDto({
    required this.raw,
    this.id,
    this.type,
    this.label,
    this.data,
  });

  final JsonMap raw;
  final String? id;
  final String? type;
  final String? label;
  final JsonMap? data;

  factory AiActionDto.fromJson(JsonMap json) {
    return AiActionDto(
      raw: json,
      id: json['id'] as String?,
      type: json['type'] as String?,
      label: json['label'] as String?,
      data: json['data'] is JsonMap ? json['data'] as JsonMap : null,
    );
  }
}

class RagChatResponseDto {
  const RagChatResponseDto({
    required this.response,
    required this.sessionId,
    required this.ragEnabled,
    required this.contextCount,
    required this.sources,
    required this.actions,
    required this.hasOptions,
    required this.numberedOptions,
    required this.usage,
  });

  final String response;
  final String sessionId;
  final bool ragEnabled;
  final int contextCount;
  final List<RagSourceDto> sources;
  final List<AiActionDto> actions;
  final bool hasOptions;
  final List<JsonMap> numberedOptions;
  final JsonMap usage;

  factory RagChatResponseDto.fromJson(JsonMap json) {
    final sourcesRaw = json['sources'] as List<dynamic>? ?? const [];
    final actionsRaw = json['actions'] as List<dynamic>? ?? const [];
    final optionsRaw = json['numbered_options'] as List<dynamic>? ?? const [];

    return RagChatResponseDto(
      response: json['response'] as String? ?? '',
      sessionId: json['session_id'] as String? ?? '',
      ragEnabled: json['rag_enabled'] == true,
      contextCount: (json['context_count'] as num?)?.toInt() ?? 0,
      sources: sourcesRaw
          .whereType<JsonMap>()
          .map(RagSourceDto.fromJson)
          .toList(),
      actions: actionsRaw
          .whereType<JsonMap>()
          .map(AiActionDto.fromJson)
          .toList(),
      hasOptions: json['has_options'] == true,
      numberedOptions: optionsRaw.whereType<JsonMap>().toList(),
      usage: json['usage'] is JsonMap
          ? json['usage'] as JsonMap
          : <String, dynamic>{},
    );
  }
}

class ConversationPreviewDto {
  const ConversationPreviewDto({
    required this.conversationId,
    required this.messageCount,
    required this.settings,
    required this.summary,
    this.title,
    this.lastMessage,
    this.lastActivityAt,
    this.createdAt,
  });

  final String conversationId;
  final int messageCount;
  final JsonMap settings;
  final String summary;
  final String? title;
  final JsonMap? lastMessage;
  final String? lastActivityAt;
  final String? createdAt;

  factory ConversationPreviewDto.fromJson(JsonMap json) {
    return ConversationPreviewDto(
      conversationId: json['conversation_id'] as String? ?? '',
      title: json['title'] as String?,
      summary: json['summary'] as String? ?? '',
      messageCount: (json['message_count'] as num?)?.toInt() ?? 0,
      settings: json['settings'] is JsonMap
          ? json['settings'] as JsonMap
          : <String, dynamic>{},
      lastMessage: json['last_message'] is JsonMap
          ? json['last_message'] as JsonMap
          : null,
      lastActivityAt: json['last_activity_at'] as String?,
      createdAt: json['created_at'] as String?,
    );
  }
}

class ConversationsResponseDto {
  const ConversationsResponseDto({
    required this.conversations,
    required this.pagination,
  });

  final List<ConversationPreviewDto> conversations;
  final JsonMap pagination;

  factory ConversationsResponseDto.fromJson(JsonMap json) {
    final list = json['conversations'] as List<dynamic>? ?? const [];

    return ConversationsResponseDto(
      conversations: list
          .whereType<JsonMap>()
          .map(ConversationPreviewDto.fromJson)
          .toList(),
      pagination: json['pagination'] is JsonMap
          ? json['pagination'] as JsonMap
          : <String, dynamic>{},
    );
  }
}
