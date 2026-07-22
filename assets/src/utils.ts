/**
 * apiFetch rejects non-2xx responses with the parsed WP_Error body
 * ({ code, message, data }) rather than a generic Error — pull the real
 * reason out of it instead of showing a one-size-fits-all fallback string.
 *
 * @param error    The rejected value from an apiFetch() call.
 * @param fallback Message to use when `error` isn't a recognisable WP_Error shape.
 */
export function getErrorMessage( error: unknown, fallback: string ): string {
	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof ( error as { message: unknown } ).message === 'string'
	) {
		return ( error as { message: string } ).message;
	}
	return fallback;
}
