import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { ApiResponse } from '../servers/server-api';

export interface Build {
  id: number;
  project_id: number | null;
  status: 'queued' | 'running' | 'success' | 'failed';
  command: string;
  local_path: string;
  build_output_path: string;
  log: string;
  duration_ms: number | null;
  exit_code: number | null;
  started_at: string | null;
  finished_at: string | null;
}

@Injectable({ providedIn: 'root' })
export class BuildApi {
  private readonly http = inject(HttpClient);

  start(projectId: number) {
    return this.http.post<ApiResponse<Build>>(`/api/projects/${projectId}/build`, {});
  }

  get(id: number) {
    return this.http.get<ApiResponse<Build>>(`/api/builds/${id}`);
  }

  latest(projectId: number) {
    return this.http.get<ApiResponse<Build | null>>(`/api/projects/${projectId}/builds/latest`);
  }
}
