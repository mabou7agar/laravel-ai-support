import 'package:dio/dio.dart';

import 'dto/api_envelope_dto.dart';
import 'dto/agent_chat_dto.dart';
import 'dto/data_collector_dto.dart';
import 'dto/generate_text_dto.dart';

class AiEngineClient {
  AiEngineClient(this._dio);

  final Dio _dio;

  Future<AgentChatResponseDto> sendChat(AgentChatRequestDto request) async {
    final json = await _postJson('/api/v1/agent/chat', data: request.toJson());
    final envelope = ApiEnvelopeDto<AgentChatResponseDto>.fromJson(
      json,
      (raw) => AgentChatResponseDto.fromJson(raw as JsonMap),
    );

    return _unwrapEnvelope(envelope);
  }

  Future<ConversationsResponseDto> getConversations({
    int limit = 20,
    int page = 1,
  }) async {
    final json = await _getJson(
      '/api/v1/agent/conversations',
      queryParameters: <String, dynamic>{'limit': limit, 'page': page},
    );

    final envelope = ApiEnvelopeDto<ConversationsResponseDto>.fromJson(
      json,
      (raw) => ConversationsResponseDto.fromJson(raw as JsonMap),
    );

    return _unwrapEnvelope(envelope);
  }

  Future<DataCollectorSessionDto> startDataCollector(
    DataCollectorStartRequestDto request,
  ) async {
    final json = await _postJson(
      '/api/v1/data-collector/start',
      data: request.toJson(),
    );

    return _parseDirect<DataCollectorSessionDto>(
      json,
      DataCollectorSessionDto.fromJson,
    );
  }

  Future<DataCollectorSessionDto> sendDataCollectorMessage(
    DataCollectorMessageRequestDto request,
  ) async {
    final json = await _postJson(
      '/api/v1/data-collector/message',
      data: request.toJson(),
    );

    return _parseDirect<DataCollectorSessionDto>(
      json,
      DataCollectorSessionDto.fromJson,
    );
  }

  Future<DataCollectorStatusDto> getDataCollectorStatus(
    String sessionId,
  ) async {
    final json = await _getJson('/api/v1/data-collector/status/$sessionId');

    return _parseDirect<DataCollectorStatusDto>(
      json,
      DataCollectorStatusDto.fromJson,
    );
  }

  Future<GenerateTextResponseDto> generateText(
    GenerateTextRequestDto request,
  ) async {
    final json = await _postJson(
      '/api/v1/ai/generate/text',
      data: request.toJson(),
    );

    final envelope = ApiEnvelopeDto<GenerateTextResponseDto>.fromJson(
      json,
      (raw) => GenerateTextResponseDto.fromJson(raw as JsonMap),
    );

    return _unwrapEnvelope(envelope);
  }

  Future<JsonMap> _getJson(String path, {JsonMap? queryParameters}) async {
    try {
      final response = await _dio.get<dynamic>(
        path,
        queryParameters: queryParameters,
      );

      return _expectJsonMap(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error);
    }
  }

  Future<JsonMap> _postJson(String path, {Object? data}) async {
    try {
      final response = await _dio.post<dynamic>(path, data: data);
      return _expectJsonMap(response.data);
    } on DioException catch (error) {
      throw _mapDioException(error);
    }
  }

  T _unwrapEnvelope<T>(ApiEnvelopeDto<T> envelope) {
    if (!envelope.success || envelope.data == null) {
      throw ApiException(
        envelope.message ??
            _extractErrorMessage(envelope.error) ??
            'Request failed.',
        payload: envelope.error,
      );
    }

    return envelope.data as T;
  }

  T _parseDirect<T>(JsonMap json, T Function(JsonMap json) parser) {
    if (json['success'] == false) {
      throw ApiException(
        (json['message'] ?? json['error'] ?? 'Request failed.').toString(),
        payload: json,
      );
    }

    return parser(json);
  }

  JsonMap _expectJsonMap(dynamic data) {
    if (data is Map<String, dynamic>) {
      return data;
    }

    throw const ApiException('Expected JSON object response.');
  }

  ApiException _mapDioException(DioException error) {
    final responseData = error.response?.data;
    String? message;

    if (responseData is Map<String, dynamic>) {
      message = (responseData['message'] ?? responseData['error'])?.toString();
    }

    return ApiException(
      message ?? error.message ?? 'Network request failed.',
      statusCode: error.response?.statusCode,
      payload: responseData,
    );
  }

  String? _extractErrorMessage(dynamic error) {
    if (error is String) {
      return error;
    }

    if (error is Map<String, dynamic>) {
      return (error['message'] ?? error['error'])?.toString();
    }

    return null;
  }
}
