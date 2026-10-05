import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { ApiResponse } from '../servers/server-api';

export interface SettingsInput {
  projects_root: string;
  npx_path: string;
  ftp_retries: number;
  ftp_retry_delay_ms: number;
  excluded_project_folders?: string[];
  hide_non_web_projects?: boolean;
}

@Injectable({ providedIn: 'root' })
export class SettingsApi {
  private readonly http = inject(HttpClient);

  get() {
    return this.http.get<ApiResponse<SettingsInput>>('/api/settings');
  }

  update(input: SettingsInput) {
    return this.http.put<ApiResponse<SettingsInput>>('/api/settings', input);
  }
}
