import 'api_envelope_dto.dart';

class GenerateTextRequestDto {
  const GenerateTextRequestDto({
    required this.prompt,
    this.engine,
    this.model,
    this.systemPrompt,
    this.maxTokens,
    this.temperature,
    this.parameters,
  });

  final String prompt;
  final String? engine;
  final String? model;
  final String? systemPrompt;
  final int? maxTokens;
  final double? temperature;
  final JsonMap? parameters;

  JsonMap toJson() {
    return <String, dynamic>{
      'prompt': prompt,
      'engine': engine,
      'model': model,
      'system_prompt': systemPrompt,
      'max_tokens': maxTokens,
      'temperature': temperature,
      'parameters': parameters,
    }..removeWhere((key, value) => value == null);
  }
}

class GenerateTextResponseDto {
  const GenerateTextResponseDto({
    required this.content,
    required this.engine,
    required this.model,
    required this.usage,
    required this.metadata,
  });

  final String content;
  final String engine;
  final String model;
  final JsonMap usage;
  final JsonMap metadata;

  factory GenerateTextResponseDto.fromJson(JsonMap json) {
    return GenerateTextResponseDto(
      content: json['content'] as String? ?? '',
      engine: json['engine'] as String? ?? '',
      model: json['model'] as String? ?? '',
      usage: json['usage'] is JsonMap
          ? json['usage'] as JsonMap
          : <String, dynamic>{},
      metadata: json['metadata'] is JsonMap
          ? json['metadata'] as JsonMap
          : <String, dynamic>{},
    );
  }
}
