import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { errorMessage } from '../shared/api-error';
import { SettingsApi } from './settings-api';

@Component({
  selector: 'app-settings',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './settings.html',
  styleUrl: '../shared/management.css',
})
export class Settings implements OnInit {
  private readonly api = inject(SettingsApi);
  private readonly fb = inject(FormBuilder);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly ready = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly form = this.fb.nonNullable.group({
    projects_root: ['', [Validators.required, Validators.maxLength(768)]],
    npx_path: ['', [Validators.required, Validators.maxLength(1024)]],
    ftp_retries: [3, [Validators.required, Validators.min(1), Validators.max(10), Validators.pattern(/^\d+$/)]],
    ftp_retry_delay_ms: [1000, [Validators.required, Validators.min(0), Validators.max(60000), Validators.pattern(/^\d+$/)]],
    excluded_folders_text: ['mobile\ndesktop\nescritorio'],
    hide_non_web_projects: [true],
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    if (this.loading() || this.saving()) return;
    this.error.set('');
    this.notice.set('');
    this.loading.set(true);
    this.api.get().pipe(finalize(() => this.loading.set(false))).subscribe({
      next: ({ data }) => {
        this.form.reset({ ...data, excluded_folders_text: (data.excluded_project_folders ?? ['mobile', 'desktop', 'escritorio']).join('\n'), hide_non_web_projects: data.hide_non_web_projects ?? true });
        this.ready.set(true);
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  save(): void {
    if (this.loading() || this.saving() || !this.ready()) return;
    this.form.markAllAsTouched();
    if (this.form.invalid) {
      this.error.set('Revisa la carpeta, la ruta de npx y los limites de reintentos y espera.');
      return;
    }
    this.error.set('');
    this.notice.set('');
    this.saving.set(true);
    const { excluded_folders_text, ...values } = this.form.getRawValue();
    this.api.update({ ...values, excluded_project_folders: excluded_folders_text.split(/\r?\n/).map((folder) => folder.trim()).filter(Boolean) }).pipe(finalize(() => this.saving.set(false))).subscribe({
      next: ({ data }) => {
        this.form.reset({ ...data, excluded_folders_text: (data.excluded_project_folders ?? []).join('\n'), hide_non_web_projects: data.hide_non_web_projects ?? true });
        this.notice.set('Configuracion guardada. El proximo escaneo usara esta carpeta.');
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }
}
