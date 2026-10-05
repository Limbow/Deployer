import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { exhaustMap, finalize, Subscription, timer } from 'rxjs';
import { Project, ProjectApi } from '../projects/project-api';
import { errorMessage } from '../shared/api-error';
import { Build, BuildApi } from './build-api';
import { FileSelection } from '../files/file-selection';

@Component({
  selector: 'app-builds',
  imports: [FormsModule, RouterLink, FileSelection],
  templateUrl: './builds.html',
  styleUrls: ['../shared/management.css', './builds.css'],
})
export class Builds implements OnInit {
  private readonly api = inject(BuildApi);
  private readonly projectsApi = inject(ProjectApi);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  private polling?: Subscription;
  readonly projects = signal<Project[]>([]);
  readonly projectId = signal(0);
  readonly serverId = signal(0);
  readonly build = signal<Build | null>(null);
  readonly loading = signal(false);
  readonly submitting = signal(false);
  readonly latestLoading = signal(false);
  readonly pollFailed = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly confirmed = signal(false);
  readonly fileReady = signal(false);
  readonly fileRevision = signal(0);
  readonly deployActive = signal(false);

  ngOnInit(): void {
    this.load();
  }

  project(): Project | undefined {
    return this.projects().find((project) => project.id === this.projectId());
  }

  active(): boolean {
    return this.build()?.status === 'queued' || this.build()?.status === 'running';
  }

  busy(): boolean {
    return this.loading() || this.submitting() || this.latestLoading();
  }

  load(): void {
    if (this.busy() || this.active() || this.deployActive()) return;
    this.error.set('');
    this.loading.set(true);
    this.projectsApi.list().pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false))).subscribe({
      next: ({ data }) => {
        this.projects.set(data);
        const requested = Number(this.route.snapshot.queryParamMap.get('project'));
        const selected = data.some((project) => project.id === requested) ? requested : this.projectId();
        this.selectProject(data.some((project) => project.id === selected) ? selected : 0);
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  selectProject(id: number): void {
    if (this.active() || this.deployActive() || this.submitting() || this.latestLoading()) return;
    this.polling?.unsubscribe();
    this.projectId.set(Number(id));
    this.serverId.set(0);
    this.build.set(null);
    this.confirmed.set(false);
    this.error.set('');
    this.notice.set('');
    this.pollFailed.set(false);
    this.fileReady.set(false);
    this.fileRevision.update((revision) => revision + 1);
    if (!this.project()) return;
    this.latestLoading.set(true);
    this.api.latest(this.projectId()).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.latestLoading.set(false))).subscribe({
      next: ({ data }) => {
        this.build.set(data);
        this.fileReady.set(data?.status === 'success');
        if (this.active()) this.watch();
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  start(): void {
    if (!this.project() || this.busy() || this.active() || this.deployActive() || !this.confirmed()) return;
    if (!this.project()?.build_command) {
      this.error.set('Este proyecto no tiene build configurado. Puedes omitirlo o editar su comando.');
      return;
    }
    this.error.set('');
    this.notice.set('');
    this.submitting.set(true);
    this.fileReady.set(false);
    this.fileRevision.update((revision) => revision + 1);
    this.api.start(this.projectId()).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.submitting.set(false))).subscribe({
      next: ({ data }) => {
        this.build.set(data);
        this.fileReady.set(data?.status === 'success');
        this.confirmed.set(false);
        this.watch();
      },
      error: (error: unknown) => {
        this.error.set(errorMessage(error));
        // A failed HTTP response does not prove the job was not accepted. Recover latest before allowing a retry.
        this.refreshLatest();
      },
    });
  }

  refreshLatest(): void {
    if (!this.project() || this.latestLoading() || this.deployActive()) return;
    this.latestLoading.set(true);
    this.api.latest(this.projectId()).pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.latestLoading.set(false))).subscribe({
      next: ({ data }) => {
        this.build.set(data);
        this.fileReady.set(data?.status === 'success');
        if (this.active()) this.watch();
      },
      error: (error: unknown) => this.error.set(errorMessage(error)),
    });
  }

  watch(): void {
    const build = this.build();
    if (!build) return;
    this.polling?.unsubscribe();
    this.error.set('');
    this.pollFailed.set(false);
    this.polling = timer(0, 1500).pipe(
      exhaustMap(() => this.api.get(build.id)),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: ({ data }) => {
        this.build.set(data);
        this.fileReady.set(data.status === 'success');
        if (data.status === 'success' || data.status === 'failed') this.polling?.unsubscribe();
      },
      error: (error: unknown) => {
        this.pollFailed.set(true);
        this.error.set(`El seguimiento se interrumpio. El job puede seguir ejecutandose. ${errorMessage(error)}`);
      },
    });
  }

  skip(): void {
    if (!this.project() || this.active() || this.deployActive() || this.busy()) return;
    this.fileReady.set(true);
    this.fileRevision.update((revision) => revision + 1);
    this.notice.set('Build omitido. No se compilo ni se subio ningun archivo. Puedes seleccionar los archivos existentes en el paso 3.');
  }

  statusLabel(status: Build['status']): string {
    return { queued: 'En cola', running: 'En ejecucion', success: 'Completado', failed: 'Fallido' }[status];
  }

  selectedServerName(): string {
    return this.project()?.servers.find((server) => server.id === this.serverId())?.name ?? '';
  }
}
