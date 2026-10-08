export { api, ApiClient, API_BASE_URL, configureApi } from './client';
export type {
  ApiClientConfig,
  ApiEnvelope,
  Page,
  PageMeta,
  QueryParams,
  RequestOptions,
} from './client';
export { ApiError, GENERIC_ERROR_MESSAGE, NETWORK_ERROR_MESSAGE } from './errors';
export type { FieldErrors } from './errors';
