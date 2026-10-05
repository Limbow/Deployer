import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { ApiResponse } from '../servers/server-api';

export type DeployStatus = 'queued' | 'running' | 'success' | 'failed' | 'rolled_back';
export type DeployFileStatus =
  | 'queued'
  | 'uploading'
  | 'uploaded'
  | 'pending_delete'
  | 'deleting'
  | 'deleted'
  | 'absent'
  | 'skipped'
  | 'failed';

export interface DeployFile {
  id: number;
  deploy_id: number;
  relative_path: string;
  remote_path: string;
  local_path: string | null;
  hash: string;
  size: number;
  status: DeployFileStatus;
  backup_path: string | null;
  error: string | null;
}

export interface Deploy {
  id: number;
  project_id: number | null;
  server_id: number | null;
  build_id: number | null;
  project_name: string | null;
  project_type: 'angular' | 'laravel' | null;
  server_name: string | null;
  source_path: string | null;
  version: string;
  changes: string | null;
  status: DeployStatus;
  progress: number;
  duration_ms: number | null;
  log: string;
  remote_path: string;
  public_remote_path: string | null;
  delete_obsolete: boolean;
  version_file_name: string | null;
  git_commit: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at?: string;
  files: DeployFile[];
}

export interface DeploySummary extends Omit<Deploy, 'files' | 'log'> {
  created_at: string;
  files_count: number;
  uploaded_files_count: number;
  deleted_files_count: number;
  absent_files_count: number;
}

export interface DeployHistoryPage {
  data: DeploySummary[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface DeployInput {
  project_id: number;
  server_id: number;
  build_id: number | null;
  version: string;
  changes: string;
  delete_obsolete: boolean;
  files: string[];
}

@Injectable({ providedIn: 'root' })
export class DeployApi {
  private readonly http = inject(HttpClient);

  list(projectId: number | null = null, page = 1, perPage = 20) {
    let params = new HttpParams().set('page', page).set('per_page', perPage);
    if (projectId !== null) params = params.set('project_id', projectId);
    return this.http.get<DeployHistoryPage>('/api/deploys', { params });
  }

  start(input: DeployInput) {
    return this.http.post<ApiResponse<Deploy>>('/api/deploys', input);
  }

  get(id: number) {
    return this.http.get<ApiResponse<Deploy>>(`/api/deploys/${id}`);
  }

  active(projectId: number, serverId: number) {
    return this.http.get<ApiResponse<Deploy | null>>(`/api/projects/${projectId}/deploys/active`, {
      params: { server_id: serverId },
    });
  }
}
