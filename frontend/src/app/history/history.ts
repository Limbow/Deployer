import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { Deploy, DeployApi, DeployFile, DeployStatus, DeploySummary } from '../deploys/deploy-api';
import { Project, ProjectApi } from '../projects/project-api';
import { errorMessage } from '../shared/api-error';

@Component({
  selector: 'app-history',
  imports: [RouterLink],
  templateUrl: './history.html',
  styleUrls: ['../shared/management.css', './history.css'],
})
export class History implements OnInit {
  private readonly api = inject(DeployApi);
  private readonly projectsApi = inject(ProjectApi);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  readonly projects = signal<Project[]>([]);
  readonly projectId = signal(0);
  readonly deploys = signal<DeploySummary[]>([]);
  readonly selectedDeployId = signal<number | null>(null);
  readonly selectedDeploy = signal<Deploy | null>(null);
  readonly loadingProjects = signal(false);
  readonly loading = signal(false);
  readonly loadingDetail = signal(false);
  readonly projectError = signal('');
  readonly error = signal('');
  readonly detailError = signal('');
  readonly meta = signal({ current_page: 1, last_page: 1, per_page: 20, total: 0 });

  ngOnInit(): void {
    const requested = Number(this.route.snapshot.queryParamMap.get('project'));
    this.loadingProjects.set(true);
    this.projectsApi.list(true)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loadingProjects.set(false)))
      .subscribe({
        next: ({ data }) => {
          this.projects.set(data);
          this.projectId.set(Number.isInteger(requested) && data.some((project) => project.id === requested) ? requested : 0);
          this.load(1);
        },
        error: (error: unknown) => {
          this.projectError.set(errorMessage(error));
          this.load(1);
        },
      });
  }

  load(page = this.meta().current_page): void {
    if (this.loading()) return;
    this.error.set('');
    this.loading.set(true);
    this.api.list(this.projectId() || null, page)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ data, meta }) => {
          this.deploys.set(data);
          this.meta.set(meta);
          if (this.selectedDeployId() !== null && !data.some((deploy) => deploy.id === this.selectedDeployId())) {
            this.closeDetail();
          }
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }

  filterProject(event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLSelectElement) || this.loadingDetail() || this.loading()) return;
    const requested = Number(target.value);
    this.projectId.set(Number.isSafeInteger(requested) && requested > 0 ? requested : 0);
    this.closeDetail();
    this.meta.set({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
    this.load(1);
  }

  selectDeploy(deploy: DeploySummary): void {
    if (this.loadingDetail()) return;
    this.selectedDeployId.set(deploy.id);
    this.selectedDeploy.set(null);
    this.detailError.set('');
    this.loadingDetail.set(true);
    this.api.get(deploy.id)
      .pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.loadingDetail.set(false)))
      .subscribe({
        next: ({ data }) => {
          if (this.selectedDeployId() === deploy.id) this.selectedDeploy.set(data);
        },
        error: (error: unknown) => this.detailError.set(errorMessage(error)),
      });
  }

  closeDetail(): void {
    this.selectedDeployId.set(null);
    this.selectedDeploy.set(null);
    this.detailError.set('');
  }

  statusLabel(status: DeployStatus): string {
    return {
      queued: 'En cola',
      running: 'En curso',
      success: 'Completado',
      failed: 'Fallido',
      rolled_back: 'Revertido',
    }[status];
  }

  statusClass(status: DeployStatus): string {
    return `status-${status}`;
  }

  fileStatusLabel(file: DeployFile): string {
    return {
      queued: 'En cola',
      uploading: 'Subiendo',
      uploaded: 'Subido',
      pending_delete: 'Pendiente de borrar',
      deleting: 'Borrando',
      deleted: 'Borrado',
      absent: 'Ya ausente',
      skipped: 'Omitido',
      failed: 'Fallido',
    }[file.status];
  }

  fileStatusClass(file: DeployFile): string {
    if (file.status === 'failed') return 'status-failed';
    if (file.status === 'uploading' || file.status === 'deleting') return 'status-running';
    if (file.status === 'queued' || file.status === 'pending_delete') return 'status-queued';
    return 'status-success';
  }

  formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? value
      : new Intl.DateTimeFormat('es-AR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  formatDuration(value: number | null): string {
    if (value === null) return '—';
    const seconds = value / 1000;
    return `${seconds.toFixed(seconds < 10 ? 1 : 0)} s`;
  }

  projectName(deploy: DeploySummary): string {
    return deploy.project_name || 'Proyecto eliminado';
  }

  previousPage(): void {
    if (this.meta().current_page > 1 && !this.loading()) this.load(this.meta().current_page - 1);
  }

  nextPage(): void {
    if (this.meta().current_page < this.meta().last_page && !this.loading()) this.load(this.meta().current_page + 1);
  }
}
