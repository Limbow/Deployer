import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { ConnectionResult, Server, ServerApi, ServerInput } from './server-api';
import { errorMessage } from '../shared/api-error';

@Component({
  selector: 'app-servers',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './servers.html',
  styleUrl: '../shared/management.css',
})
export class Servers implements OnInit {
  private readonly api = inject(ServerApi);
  private readonly fb = inject(FormBuilder);
  readonly servers = signal<Server[]>([]);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly testingId = signal<number | null>(null);
  readonly deletingId = signal<number | null>(null);
  readonly editingId = signal<number | null>(null);
  readonly deleteCandidate = signal<Server | null>(null);
  readonly error = signal('');
  readonly notice = signal('');
  readonly result = signal<{ name: string; connection: ConnectionResult } | null>(null);

  readonly form = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    host: ['', [Validators.required, Validators.maxLength(253)]],
    port: [21, [Validators.required, Validators.min(1), Validators.max(65535), Validators.pattern(/^\d+$/)]],
    username: ['', [Validators.required, Validators.maxLength(255)]],
    password: ['', [Validators.required, Validators.maxLength(4096)]],
    use_ftps: [true],
    passive: [true],
    remote_path: ['/', [Validators.required, Validators.maxLength(1024), Validators.pattern(/^\/[^\x00-\x1f\x7f\\]*$/)]],
  });

  ngOnInit(): void {
    this.load();
  }

  busy(): boolean {
    return this.loading() || this.saving() || this.testingId() !== null || this.deletingId() !== null;
  }

  load(): void {
    if (this.busy()) return;
    this.error.set('');
    this.loading.set(true);
    this.api.list().pipe(finalize(() => this.loading.set(false))).subscribe({
      next: ({ data }) => this.servers.set(data),
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  edit(server: Server): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.deleteCandidate.set(null);
    this.editingId.set(server.id);
    this.form.controls.password.setValidators([Validators.maxLength(4096)]);
    this.form.reset({ ...server, password: '' });
    this.form.controls.password.updateValueAndValidity();
  }

  cancel(): void {
    if (this.busy()) return;
    this.resetForm();
    this.clearFeedback();
  }

  save(): void {
    if (this.busy()) return;
    this.form.markAllAsTouched();
    if (this.form.invalid) {
      this.error.set('Revisa los campos obligatorios, el puerto y la ruta remota.');
      return;
    }

    this.clearFeedback();
    const id = this.editingId();
    const input: ServerInput = this.form.getRawValue();
    if (id !== null && input.password === '') {
      delete input.password;
    }
    this.saving.set(true);
    const request = id === null ? this.api.create(input) : this.api.update(id, input);
    request.pipe(finalize(() => this.saving.set(false))).subscribe({
      next: ({ data }) => {
        this.servers.update((servers) => this.sort([...servers.filter((server) => server.id !== data.id), data]));
        this.resetForm();
        this.notice.set(id === null ? 'Servidor creado.' : 'Servidor actualizado.');
      },
      error: (error: unknown) => {
        this.form.controls.password.reset('');
        this.error.set(errorMessage(error));
      },
    });
  }

  requestDelete(server: Server): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.deleteCandidate.set(server);
  }

  remove(): void {
    const server = this.deleteCandidate();
    if (server === null || this.busy()) return;
    this.clearFeedback();
    this.deletingId.set(server.id);
    this.api.delete(server.id).pipe(finalize(() => this.deletingId.set(null))).subscribe({
      next: () => {
        this.servers.update((servers) => servers.filter((item) => item.id !== server.id));
        this.deleteCandidate.set(null);
        if (this.editingId() === server.id) this.resetForm();
        this.notice.set('Servidor eliminado.');
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  test(server: Server): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.testingId.set(server.id);
    this.api.test(server.id).pipe(finalize(() => this.testingId.set(null))).subscribe({
      next: ({ data }) => this.result.set({ name: server.name, connection: data }),
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  private resetForm(): void {
    this.editingId.set(null);
    this.form.controls.password.setValidators([Validators.required, Validators.maxLength(4096)]);
    this.form.reset({
      name: '', host: '', port: 21, username: '', password: '',
      use_ftps: true, passive: true, remote_path: '/',
    });
    this.form.controls.password.updateValueAndValidity();
  }

  private clearFeedback(): void {
    this.error.set('');
    this.notice.set('');
    this.result.set(null);
  }

  private sort(servers: Server[]): Server[] {
    return servers.sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id);
  }
}
