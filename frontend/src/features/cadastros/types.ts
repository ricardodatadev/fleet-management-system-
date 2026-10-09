import type { components } from '@/api/schema';

export type Branch = components['schemas']['Branch'];
export type BranchType = Branch['type'];
export type CostCenter = components['schemas']['CostCenter'];

export type EquipmentCategory =
  'light_vehicle' | 'truck' | 'agri_machine' | 'implement' | 'support';
export type Criticality = 'low' | 'medium' | 'high' | 'critical';

/**
 * Família/Classe (spec C.4). A API chega com a F1-13; até lá o tipo espelha o contrato combinado
 * com o backend (preventive_lead_pct como número JSON, tolerâncias int|null). Trocar pelo tipo
 * gerado (`components['schemas']['EquipmentFamily']`) após o `gen:api` da F1-13.
 */
export interface EquipmentFamily {
  id: number;
  code: string;
  name: string;
  category: EquipmentCategory;
  criticality: Criticality;
  preventive_lead_pct: number;
  tolerance_km: number | null;
  tolerance_hours: number | null;
  tolerance_days: number | null;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/** `/meta/enums`: cada cadastro acrescenta as suas chaves (arrays de strings). */
export interface MetaEnums {
  branch_types: BranchType[];
  equipment_categories?: EquipmentCategory[];
  criticalities?: Criticality[];
}
