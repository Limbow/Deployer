import { HttpErrorResponse } from '@angular/common/http';

export function errorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 0) {
      return 'No se pudo contactar con Laravel. Verifica que el servidor local este iniciado.';
    }

    const body: unknown = error.error;
    if (body !== null && typeof body === 'object') {
      if ('errors' in body && body.errors !== null && typeof body.errors === 'object') {
        const messages = Object.values(body.errors)
          .filter((value): value is string[] => Array.isArray(value) && value.every((item) => typeof item === 'string'))
          .flat();
        if (messages.length) return messages.join(' ');
      }
      if ('message' in body && typeof body.message === 'string') return body.message;
    }
    return `La operacion fallo (HTTP ${error.status}).`;
  }
  return 'Ocurrio un error inesperado. Vuelve a intentarlo.';
}
