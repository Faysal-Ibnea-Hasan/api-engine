import { Form } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Permanent deletion, for active and archived projects alike. Confirmed by typing the name. */
export default function DeleteProject({
    project,
}: {
    project: { id: string; name: string };
}) {
    return (
        <section className="flex flex-1 flex-col gap-3 rounded-lg border border-destructive/20 bg-destructive/5 p-3">
            <Heading
                variant="small"
                title="Delete project"
                icon={TriangleAlert}
                description="Permanently remove this project and everything imported for it."
            />
            <p className="text-sm text-destructive">
                This deletes all snapshots, uploaded source, framework
                detections and discovered endpoints. It cannot be undone. To
                keep the project but hide it, archive it instead.
            </p>
            <Dialog>
                <DialogTrigger asChild>
                    <Button className="mt-auto w-fit" variant="destructive">
                        Delete project
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>
                        Delete {project.name} permanently?
                    </DialogTitle>
                    <DialogDescription>
                        All of its snapshots, stored source files, detections
                        and endpoints are removed for good. Type the project
                        name to confirm.
                    </DialogDescription>
                    <Form
                        {...ProjectController.destroy.form(project.id)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="confirm_name">
                                        Project name
                                    </Label>
                                    <Input
                                        id="confirm_name"
                                        name="confirm_name"
                                        autoComplete="off"
                                        placeholder={project.name}
                                        required
                                    />
                                    <InputError message={errors.confirm_name} />
                                </div>
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Delete permanently
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
