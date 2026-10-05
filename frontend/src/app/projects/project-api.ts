import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { ApiResponse, Server } from '../servers/server-api';

export interface ProjectServerInput {
  server_id: number;
  label: string;
  remote_path_override: string | null;
  public_remote_path?: string | null;
}

export interface ProjectInput {
  name: string;
  local_path: string;
  type: 'angular' | 'laravel';
  angular_project: string | null;
  build_output_path: string;
  build_command: string | null;
  ignore_patterns: string[];
  current_version: string;
  servers: ProjectServerInput[];
}

export interface Project extends Omit<ProjectInput, 'servers'> {
  id: number;
  servers: (Server & { pivot: { label: string; remote_path_override: string | null; public_remote_path?: string | null } })[];
  hidden_reason?: string | null;
  created_at: string;
  updated_at: string;
}

export interface ProjectVersionBump {
  project: Project;
  updated_files: string[];
}

export interface ProjectCandidate {
  name: string;
  local_path: string;
  type: 'angular' | 'laravel';
  angular_project: string | null;
  builder: string;
  build_output_path: string;
  build_command: string | null;
  hidden_reason?: string | null;
  current_version: string;
  registered_id: number | null;
  registered_angular_project: string | null;
}

export interface ScanResult {
  projects: ProjectCandidate[];
  issues: { local_path: string; angular_project?: string; message: string }[];
  hidden_count?: number;
}

@Injectable({ providedIn: 'root' })
export class ProjectApi {
  private readonly http = inject(HttpClient);
  private readonly url = '/api/projects';

  list(includeHidden = false) {
    return this.http.get<ApiResponse<Project[]>>(includeHidden ? `${this.url}?include_hidden=1` : this.url);
  }

  scan(includeHidden = false) {
    return this.http.get<ApiResponse<ScanResult>>(`${this.url}/scan${includeHidden ? '?include_hidden=1' : ''}`);
  }

  create(input: ProjectInput) {
    return this.http.post<ApiResponse<Project>>(this.url, input);
  }

  update(id: number, input: ProjectInput) {
    return this.http.put<ApiResponse<Project>>(`${this.url}/${id}`, input);
  }

  bumpVersion(id: number, type: 'patch' | 'minor' | 'major') {
    return this.http.post<ApiResponse<ProjectVersionBump>>(`${this.url}/${id}/bump-version`, { type });
  }

  delete(id: number) {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  visibility(path: string, hidden: boolean) {
    return this.http.post<ApiResponse<{local_path: string; hidden: boolean}>>(`${this.url}/visibility`, { local_path: path, hidden });
  }
}
