import api from "../../../api/axios";

export type AdvisorInfoFolder = {
  id: string;
  name: string;
  webUrl?: string | null;
  updatedAt?: string | null;
  childCount?: number;
};

export type AdvisorInfoFile = {
  id: string;
  name: string;
  extension: string;
  mimeType?: string | null;
  size: number;
  webUrl?: string | null;
  updatedAt?: string | null;
  previewable: boolean;
};

export type AdvisorInfoIndex = {
  root_folder: string;
  providers: AdvisorInfoFolder[];
  root_files: AdvisorInfoFile[];
};

export type AdvisorInfoProviderResponse = {
  provider: AdvisorInfoFolder;
  files: AdvisorInfoFile[];
  folders: AdvisorInfoFolder[];
};

export async function getAdvisorInfoIndex() {
  const { data } = await api.get<AdvisorInfoIndex>("advisor-info");
  return data;
}

export async function getAdvisorInfoProvider(providerId: string) {
  const { data } = await api.get<AdvisorInfoProviderResponse>(
    `advisor-info/providers/${encodeURIComponent(providerId)}`,
  );
  return data;
}

export function getAdvisorInfoContentUrl(fileId: string) {
  return `${api.defaults.baseURL}/advisor-info/files/${encodeURIComponent(fileId)}/content`;
}
