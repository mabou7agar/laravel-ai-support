typedef JsonMap = Map<String, dynamic>;

class ApiEnvelopeDto<T> {
  const ApiEnvelopeDto({
    required this.success,
    required this.message,
    required this.data,
    required this.error,
    required this.meta,
  });

  final bool success;
  final String? message;
  final T? data;
  final dynamic error;
  final JsonMap? meta;

  factory ApiEnvelopeDto.fromJson(
    JsonMap json,
    T Function(dynamic rawData) dataParser,
  ) {
    return ApiEnvelopeDto<T>(
      success: json['success'] == true,
      message: json['message'] as String?,
      data: json.containsKey('data') && json['data'] != null
          ? dataParser(json['data'])
          : null,
      error: json['error'],
      meta: json['meta'] is JsonMap ? json['meta'] as JsonMap : null,
    );
  }
}

class ApiException implements Exception {
  const ApiException(this.message, {this.statusCode, this.payload});

  final String message;
  final int? statusCode;
  final dynamic payload;

  @override
  String toString() =>
      'ApiException(statusCode: $statusCode, message: $message)';
}
