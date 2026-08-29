import ProjectDetailClient from "./ProjectDetailClient";

export const metadata = { title: "Project" };

export default async function ProjectPage({
    params,
}: {
    params: Promise<{ id: string }>;
}) {
    const { id } = await params;

    return <ProjectDetailClient projectId={id} />;
}
