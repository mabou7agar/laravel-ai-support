import 'api_envelope_dto.dart';

class DataCollectorStartRequestDto {
  const DataCollectorStartRequestDto({
    required this.configName,
    this.sessionId,
    this.initialData,
  });

  final String configName;
  final String? sessionId;
  final JsonMap? initialData;

  JsonMap toJson() {
    return <String, dynamic>{
      'config_name': configName,
      'session_id': sessionId,
      'initial_data': initialData,
    }..removeWhere((key, value) => value == null);
  }
}

class DataCollectorMessageRequestDto {
  const DataCollectorMessageRequestDto({
    required this.sessionId,
    required this.message,
    this.engine,
    this.model,
    this.configName,
  });

  final String sessionId;
  final String message;
  final String? engine;
  final String? model;
  final String? configName;

  JsonMap toJson() {
    return <String, dynamic>{
      'session_id': sessionId,
      'message': message,
      'engine': engine,
      'model': model,
      'config_name': configName,
    }..removeWhere((key, value) => value == null);
  }
}

class DataCollectorSessionDto {
  const DataCollectorSessionDto({
    required this.success,
    required this.sessionId,
    required this.message,
    required this.actions,
    required this.metadata,
    required this.data,
    required this.progress,
    this.configName,
    this.currentField,
    this.status,
    this.isComplete = false,
    this.isCancelled = false,
    this.requiresConfirmation = false,
  });

  final bool success;
  final String sessionId;
  final String message;
  final List<JsonMap> actions;
  final JsonMap metadata;
  final JsonMap data;
  final num progress;
  final String? configName;
  final String? currentField;
  final String? status;
  final bool isComplete;
  final bool isCancelled;
  final bool requiresConfirmation;

  factory DataCollectorSessionDto.fromJson(JsonMap json) {
    final actionsRaw = json['actions'] as List<dynamic>? ?? const [];

    return DataCollectorSessionDto(
      success: json['success'] == true,
      sessionId: json['session_id'] as String? ?? '',
      message: json['message'] as String? ?? '',
      actions: actionsRaw.whereType<JsonMap>().toList(),
      metadata: json['metadata'] is JsonMap
          ? json['metadata'] as JsonMap
          : <String, dynamic>{},
      data: json['data'] is JsonMap
          ? json['data'] as JsonMap
          : <String, dynamic>{},
      progress: (json['progress'] as num?) ?? 0,
      configName: json['config_name'] as String?,
      currentField: json['current_field'] as String?,
      status: json['status'] as String?,
      isComplete: json['is_complete'] == true,
      isCancelled: json['is_cancelled'] == true,
      requiresConfirmation: json['requires_confirmation'] == true,
    );
  }
}

class DataCollectorStatusDto {
  const DataCollectorStatusDto({
    required this.success,
    required this.sessionId,
    required this.status,
    required this.data,
    required this.isComplete,
    required this.isCancelled,
    required this.validationErrors,
    this.currentField,
  });

  final bool success;
  final String sessionId;
  final String status;
  final JsonMap data;
  final bool isComplete;
  final bool isCancelled;
  final List<String> validationErrors;
  final String? currentField;

  factory DataCollectorStatusDto.fromJson(JsonMap json) {
    final errors = json['validation_errors'] as List<dynamic>? ?? const [];

    return DataCollectorStatusDto(
      success: json['success'] == true,
      sessionId: json['session_id'] as String? ?? '',
      status: json['status'] as String? ?? '',
      data: json['data'] is JsonMap
          ? json['data'] as JsonMap
          : <String, dynamic>{},
      isComplete: json['is_complete'] == true,
      isCancelled: json['is_cancelled'] == true,
      validationErrors: errors.map((item) => item.toString()).toList(),
      currentField: json['current_field'] as String?,
    );
  }
}
