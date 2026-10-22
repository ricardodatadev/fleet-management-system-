import type { components, operations } from '@/api/schema';

/** Evento do audit trail (F.1 da Fase 1). */
export type AuditLog = components['schemas']['AuditLog'];
export type AuditAction = AuditLog['action'];
export type AuditActor = NonNullable<AuditLog['actor']>;

type AuditQuery = NonNullable<operations['auditLogsIndex']['parameters']['query']>;
/** Aliases aceitos no filtro `auditable_type` (crescem com a OpenAPI, ex.: OS na F2-04). */
export type AuditableType = NonNullable<AuditQuery['auditable_type']>;
